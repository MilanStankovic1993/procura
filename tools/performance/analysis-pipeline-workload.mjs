import { readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import process from 'node:process';

const CONTRACT_VERSION = 'analysis-pipeline-workload-report:v1';
const TERMINAL_SUCCESS = new Set(['completed', 'needs_input', 'archived']);
const MAXIMUM_RESPONSE_BYTES = 2 * 1024 * 1024;
const DEFAULT_CONCURRENCY = 10;
const MAXIMUM_CONCURRENCY = 20;
const DEFAULT_REQUEST_TIMEOUT_MS = 15_000;
const DEFAULT_PIPELINE_TIMEOUT_MS = 180_000;
const DEFAULT_POLL_INTERVAL_MS = 2_000;
const AUTHENTICATED_REQUESTS_PER_MINUTE = 45;

export class WorkloadError extends Error {
    constructor(code, status = null) {
        super(code);
        this.name = 'WorkloadError';
        this.code = code;
        this.status = status;
    }
}

export class CookieJar {
    #cookies = new Map();

    capture(headers) {
        const values = typeof headers.getSetCookie === 'function'
            ? headers.getSetCookie()
            : splitSetCookieHeader(headers.get('set-cookie'));

        for (const value of values) {
            const pair = value.split(';', 1)[0];
            const separator = pair.indexOf('=');

            if (separator <= 0) {
                continue;
            }

            const name = pair.slice(0, separator).trim();
            const cookieValue = pair.slice(separator + 1).trim();

            if (cookieValue === '') {
                this.#cookies.delete(name);
            } else {
                this.#cookies.set(name, cookieValue);
            }
        }
    }

    header() {
        return [...this.#cookies.entries()]
            .map(([name, value]) => `${name}=${value}`)
            .join('; ');
    }

    csrfToken() {
        const value = this.#cookies.get('XSRF-TOKEN');

        if (!value) {
            throw new WorkloadError('csrf_cookie_missing');
        }

        try {
            return decodeURIComponent(value);
        } catch {
            throw new WorkloadError('csrf_cookie_invalid');
        }
    }
}

class RequestGate {
    #tail = Promise.resolve();
    #nextAt = 0;

    constructor(requestsPerMinute) {
        this.intervalMilliseconds = 60_000 / requestsPerMinute;
    }

    run(callback) {
        const run = async () => {
            const waitMilliseconds = Math.max(0, this.#nextAt - Date.now());

            if (waitMilliseconds > 0) {
                await delay(waitMilliseconds);
            }

            this.#nextAt = Date.now() + this.intervalMilliseconds;

            return callback();
        };
        const result = this.#tail.then(run, run);
        this.#tail = result.catch(() => undefined);

        return result;
    }
}

class ActorClient {
    constructor(baseUrl, actor, permit, requestTimeoutMilliseconds) {
        this.baseUrl = baseUrl;
        this.actor = actor;
        this.permit = permit;
        this.requestTimeoutMilliseconds = requestTimeoutMilliseconds;
        this.cookies = new CookieJar();
        this.gate = new RequestGate(AUTHENTICATED_REQUESTS_PER_MINUTE);
    }

    async authenticate() {
        await this.#request('/sanctum/csrf-cookie', { method: 'GET' });
        await this.#request('/api/v1/auth/login', {
            method: 'POST',
            body: {
                email: this.actor.email,
                password: this.actor.password,
                remember: false,
            },
            csrf: true,
        });
        const me = await this.api('/api/v1/me', { method: 'GET' });

        if (
            me.status !== 200
            || typeof me.data?.data?.id !== 'string'
            && typeof me.data?.data?.id !== 'number'
        ) {
            throw new WorkloadError('actor_identity_invalid', me.status);
        }
    }

    api(path, options) {
        return this.gate.run(() => this.#request(path, {
            ...options,
            csrf: options.method !== 'GET',
        }));
    }

    async #request(path, { method, body = null, csrf = false, workload = false }) {
        const headers = {
            Accept: 'application/json',
            'Accept-Language': 'en',
            Origin: this.baseUrl,
            Referer: `${this.baseUrl}/`,
        };
        const cookie = this.cookies.header();

        if (cookie !== '') {
            headers.Cookie = cookie;
        }

        if (body !== null) {
            headers['Content-Type'] = 'application/json';
        }

        if (csrf) {
            headers['X-XSRF-TOKEN'] = this.cookies.csrfToken();
        }

        if (workload) {
            headers['X-Procura-Analysis-Workload-Permit'] = this.permit;
        }

        const startedAt = performance.now();
        let response;

        try {
            response = await fetch(`${this.baseUrl}${path}`, {
                method,
                headers,
                body: body === null ? undefined : JSON.stringify(body),
                redirect: 'error',
                signal: AbortSignal.timeout(this.requestTimeoutMilliseconds),
            });
        } catch {
            throw new WorkloadError('http_transport_failed');
        }

        const durationMilliseconds = performance.now() - startedAt;
        this.cookies.capture(response.headers);
        const length = Number(response.headers.get('content-length') ?? 0);

        if (Number.isFinite(length) && length > MAXIMUM_RESPONSE_BYTES) {
            throw new WorkloadError('http_response_too_large', response.status);
        }

        const text = await response.text();

        if (Buffer.byteLength(text, 'utf8') > MAXIMUM_RESPONSE_BYTES) {
            throw new WorkloadError('http_response_too_large', response.status);
        }

        let data = null;

        if (text !== '') {
            try {
                data = JSON.parse(text);
            } catch {
                throw new WorkloadError('http_response_invalid', response.status);
            }
        }

        return {
            status: response.status,
            durationMilliseconds,
            data,
        };
    }
}

export function percentile(values, percentileValue) {
    if (values.length === 0) {
        return null;
    }

    const sorted = [...values].sort((left, right) => left - right);
    const index = Math.max(
        0,
        Math.ceil((percentileValue / 100) * sorted.length) - 1,
    );

    return Math.round(sorted[index] * 1000) / 1000;
}

export function latencyPercentiles(values) {
    return {
        p50: percentile(values, 50),
        p95: percentile(values, 95),
        p99: percentile(values, 99),
    };
}

export function validateWorkloadInputs(permitFile, accountsFile, scenariosFile) {
    if (
        permitFile?.version !== 1
        || typeof permitFile.permit !== 'string'
        || !/^[A-Za-z0-9_-]{43}$/.test(permitFile.permit)
        || !Number.isInteger(permitFile.actors)
        || !Number.isInteger(permitFile.scenarios)
        || !Number.isInteger(permitFile.mutation_requests)
        || permitFile.mutation_requests !== permitFile.scenarios * 2
        || Date.parse(permitFile.expires_at) <= Date.now()
        || typeof permitFile.evidence_eligible !== 'boolean'
        || !validBudgets(permitFile.budgets)
    ) {
        throw new WorkloadError('permit_file_invalid');
    }

    const actors = accountsFile?.actors;

    if (
        !Array.isArray(actors)
        || actors.length !== permitFile.actors
        || actors.length < 1
        || actors.length > 50
    ) {
        throw new WorkloadError('accounts_file_invalid');
    }

    const actorKeys = new Set();
    const actorEmails = new Set();

    for (const actor of actors) {
        if (
            typeof actor?.key !== 'string'
            || !/^[a-zA-Z0-9_-]{1,64}$/.test(actor.key)
            || actorKeys.has(actor.key)
            || typeof actor.email !== 'string'
            || !actor.email.includes('@')
            || actorEmails.has(actor.email.toLowerCase())
            || typeof actor.password !== 'string'
            || actor.password.length < 1
        ) {
            throw new WorkloadError('accounts_file_invalid');
        }

        actorKeys.add(actor.key);
        actorEmails.add(actor.email.toLowerCase());
    }

    const scenarios = scenariosFile?.scenarios;

    if (
        !Array.isArray(scenarios)
        || scenarios.length !== permitFile.scenarios
        || scenarios.length < 1
        || scenarios.length > 250
    ) {
        throw new WorkloadError('scenarios_file_invalid');
    }

    const uniquePairs = new Set();

    for (const scenario of scenarios) {
        const pair = `${scenario?.listing_id}:${scenario?.target_country_code}`;

        if (
            typeof scenario?.actor !== 'string'
            || !actorKeys.has(scenario.actor)
            || typeof scenario.listing_id !== 'string'
            || !/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(scenario.listing_id)
            || typeof scenario.target_country_code !== 'string'
            || !/^[A-Z]{2}$/.test(scenario.target_country_code)
            || uniquePairs.has(pair)
        ) {
            throw new WorkloadError('scenarios_file_invalid');
        }

        uniquePairs.add(pair);
    }

    return { actors, scenarios };
}

export function buildReport({
    startedAt,
    finishedAt,
    expectedScenarios,
    actorCount,
    concurrency,
    results,
    httpStatusCounts,
    budgets,
    evidenceEligible,
}) {
    const successful = results.filter((result) => result.success);
    const failed = results.length - successful.length;
    const durationMilliseconds = Math.max(1, finishedAt - startedAt);
    const throughputPerSecond = successful.length / (durationMilliseconds / 1000);
    const failureRateBasisPoints = Math.round(
        (failed / expectedScenarios) * 10_000,
    );
    const draftLatency = latencyPercentiles(
        results.flatMap((result) => result.draftMilliseconds ?? []),
    );
    const submitLatency = latencyPercentiles(
        results.flatMap((result) => result.submitMilliseconds ?? []),
    );
    const pipelineLatency = latencyPercentiles(
        results.flatMap((result) => result.pipelineMilliseconds ?? []),
    );
    const terminalStatuses = countValues(
        successful.map((result) => result.terminalStatus),
    );
    const failureCodes = countValues(
        results.filter((result) => !result.success)
            .map((result) => result.failureCode),
    );
    const violations = [];

    if (!evidenceEligible) {
        violations.push('production_shaped_providers');
    }

    if (successful.length !== expectedScenarios) {
        violations.push('scenario_completion');
    }

    if (throughputPerSecond < budgets.minimum_throughput_per_second) {
        violations.push('minimum_throughput');
    }

    if (draftLatency.p95 > budgets.maximum_draft_p95_milliseconds) {
        violations.push('draft_p95');
    }

    if (submitLatency.p95 > budgets.maximum_submit_p95_milliseconds) {
        violations.push('submit_p95');
    }

    if (pipelineLatency.p95 > budgets.maximum_pipeline_p95_milliseconds) {
        violations.push('pipeline_p95');
    }

    if (failureRateBasisPoints > budgets.maximum_failure_rate_basis_points) {
        violations.push('failure_rate');
    }

    if ((httpStatusCounts['429'] ?? 0) > 0) {
        violations.push('rate_limit');
    }

    if (Object.entries(httpStatusCounts).some(
        ([status, count]) => Number(status) >= 500 && count > 0,
    )) {
        violations.push('server_error');
    }

    return {
        contract_version: CONTRACT_VERSION,
        budget_version: budgets.version,
        evidence_eligible: evidenceEligible,
        status: violations.length === 0 ? 'passed' : 'failed',
        started_at: new Date(startedAt).toISOString(),
        finished_at: new Date(finishedAt).toISOString(),
        actors: actorCount,
        concurrency,
        scenarios: {
            expected: expectedScenarios,
            completed: successful.length,
            failed,
        },
        duration_milliseconds: Math.round(durationMilliseconds * 1000) / 1000,
        throughput_per_second: Math.round(throughputPerSecond * 1000) / 1000,
        failure_rate_basis_points: failureRateBasisPoints,
        latency_milliseconds: {
            draft: draftLatency,
            submit: submitLatency,
            pipeline: pipelineLatency,
        },
        terminal_status_counts: terminalStatuses,
        failure_code_counts: failureCodes,
        http_status_counts: sortObject(httpStatusCounts),
        budgets,
        violations,
    };
}

async function main() {
    try {
        const configuration = await loadConfiguration();
        const actorClients = new Map(configuration.actors.map((actor) => [
            actor.key,
            new ActorClient(
                configuration.baseUrl,
                actor,
                configuration.permit.permit,
                configuration.requestTimeoutMilliseconds,
            ),
        ]));

        await Promise.all([...actorClients.values()].map(
            (client) => client.authenticate(),
        ));

        const startedAt = Date.now();
        const httpStatusCounts = {};
        const results = await runPool(
            configuration.scenarios,
            configuration.concurrency,
            (scenario) => runScenario(
                scenario,
                actorClients.get(scenario.actor),
                configuration.pipelineTimeoutMilliseconds,
                configuration.pollIntervalMilliseconds,
                httpStatusCounts,
            ),
        );
        const finishedAt = Date.now();
        const report = buildReport({
            startedAt,
            finishedAt,
            expectedScenarios: configuration.scenarios.length,
            actorCount: configuration.actors.length,
            concurrency: configuration.concurrency,
            results,
            httpStatusCounts,
            budgets: configuration.permit.budgets,
            evidenceEligible: configuration.permit.evidence_eligible,
        });

        process.stdout.write(`${JSON.stringify(report)}\n`);
        process.exitCode = report.status === 'passed' ? 0 : 1;
    } catch (error) {
        process.stdout.write(`${JSON.stringify({
            contract_version: CONTRACT_VERSION,
            status: 'failed',
            error_code: error instanceof WorkloadError
                ? error.code
                : 'workload_setup_failed',
        })}\n`);
        process.exitCode = 1;
    }
}

async function loadConfiguration() {
    const baseUrl = validBaseUrl(
        requiredEnvironment('PROCURA_ANALYSIS_LOAD_BASE_URL'),
    );
    const [permit, accounts, scenarios] = await Promise.all([
        readJsonFile(requiredEnvironment('PROCURA_ANALYSIS_LOAD_PERMIT_FILE')),
        readJsonFile(requiredEnvironment('PROCURA_ANALYSIS_LOAD_ACCOUNTS_FILE')),
        readJsonFile(requiredEnvironment('PROCURA_ANALYSIS_LOAD_SCENARIOS_FILE')),
    ]);
    const inputs = validateWorkloadInputs(permit, accounts, scenarios);

    return {
        baseUrl,
        permit,
        actors: inputs.actors,
        scenarios: inputs.scenarios,
        concurrency: environmentInteger(
            'PROCURA_ANALYSIS_LOAD_CONCURRENCY',
            DEFAULT_CONCURRENCY,
            1,
            MAXIMUM_CONCURRENCY,
        ),
        requestTimeoutMilliseconds: environmentInteger(
            'PROCURA_ANALYSIS_LOAD_REQUEST_TIMEOUT_MS',
            DEFAULT_REQUEST_TIMEOUT_MS,
            1_000,
            60_000,
        ),
        pipelineTimeoutMilliseconds: environmentInteger(
            'PROCURA_ANALYSIS_LOAD_PIPELINE_TIMEOUT_MS',
            DEFAULT_PIPELINE_TIMEOUT_MS,
            10_000,
            3_600_000,
        ),
        pollIntervalMilliseconds: environmentInteger(
            'PROCURA_ANALYSIS_LOAD_POLL_INTERVAL_MS',
            DEFAULT_POLL_INTERVAL_MS,
            1_000,
            30_000,
        ),
    };
}

async function runScenario(
    scenario,
    client,
    pipelineTimeoutMilliseconds,
    pollIntervalMilliseconds,
    httpStatusCounts,
) {
    const result = { success: false };

    try {
        const draft = await client.api('/api/v1/buy-analyses', {
            method: 'POST',
            body: {
                listing_id: scenario.listing_id,
                target_country_code: scenario.target_country_code,
            },
            workload: true,
        });
        countStatus(httpStatusCounts, draft.status);
        result.draftMilliseconds = [draft.durationMilliseconds];

        if (
            ![200, 201].includes(draft.status)
            || typeof draft.data?.data?.id !== 'string'
            || draft.data?.data?.status !== 'draft'
        ) {
            throw new WorkloadError('draft_request_failed', draft.status);
        }

        const analysisId = encodeURIComponent(draft.data.data.id);
        const pipelineStartedAt = performance.now();
        const submission = await client.api(
            `/api/v1/analyses/${analysisId}/submit`,
            { method: 'POST', workload: true },
        );
        countStatus(httpStatusCounts, submission.status);
        result.submitMilliseconds = [submission.durationMilliseconds];

        if (submission.status !== 202) {
            throw new WorkloadError('submit_request_failed', submission.status);
        }

        const deadline = performance.now() + pipelineTimeoutMilliseconds;
        let analysis = submission.data?.data;

        while (!terminal(analysis)) {
            if (performance.now() >= deadline) {
                throw new WorkloadError('pipeline_timeout');
            }

            await delay(pollIntervalMilliseconds);
            const poll = await client.api(
                `/api/v1/analyses/${analysisId}`,
                { method: 'GET' },
            );
            countStatus(httpStatusCounts, poll.status);

            if (poll.status !== 200) {
                throw new WorkloadError('poll_request_failed', poll.status);
            }

            analysis = poll.data?.data;
        }

        result.pipelineMilliseconds = [performance.now() - pipelineStartedAt];
        result.terminalStatus = analysis.status;

        if (!TERMINAL_SUCCESS.has(analysis.status)) {
            throw new WorkloadError('pipeline_terminal_failure');
        }

        result.success = true;
    } catch (error) {
        result.failureCode = error instanceof WorkloadError
            ? error.code
            : 'scenario_failed';

    }

    return result;
}

function terminal(analysis) {
    if (!analysis || typeof analysis.status !== 'string') {
        return false;
    }

    return TERMINAL_SUCCESS.has(analysis.status)
        || analysis.status === 'failed' && analysis.next_retry_at === null;
}

async function runPool(items, concurrency, callback) {
    const results = new Array(items.length);
    let nextIndex = 0;

    async function worker() {
        while (true) {
            const index = nextIndex++;

            if (index >= items.length) {
                return;
            }

            results[index] = await callback(items[index]);
        }
    }

    await Promise.all(Array.from(
        { length: Math.min(concurrency, items.length) },
        () => worker(),
    ));

    return results;
}

function validBudgets(budgets) {
    return typeof budgets?.version === 'string'
        && /^[a-z0-9:_-]{1,128}$/.test(budgets.version)
        && Number.isFinite(budgets.minimum_throughput_per_second)
        && budgets.minimum_throughput_per_second > 0
        && positiveInteger(budgets.maximum_draft_p95_milliseconds)
        && positiveInteger(budgets.maximum_submit_p95_milliseconds)
        && positiveInteger(budgets.maximum_pipeline_p95_milliseconds)
        && Number.isInteger(budgets.maximum_failure_rate_basis_points)
        && budgets.maximum_failure_rate_basis_points >= 0
        && budgets.maximum_failure_rate_basis_points <= 10_000;
}

function positiveInteger(value) {
    return Number.isInteger(value) && value > 0;
}

function validBaseUrl(value) {
    let url;

    try {
        url = new URL(value);
    } catch {
        throw new WorkloadError('base_url_invalid');
    }

    if (
        url.protocol !== 'https:'
        || url.username !== ''
        || url.password !== ''
        || url.search !== ''
        || url.hash !== ''
        || !['', '/'].includes(url.pathname)
    ) {
        throw new WorkloadError('base_url_invalid');
    }

    return url.origin;
}

function requiredEnvironment(key) {
    const value = process.env[key];

    if (typeof value !== 'string' || value.trim() === '') {
        throw new WorkloadError('environment_invalid');
    }

    return value.trim();
}

function environmentInteger(key, fallback, minimum, maximum) {
    const value = process.env[key];

    if (value === undefined || value === '') {
        return fallback;
    }

    if (!/^\d+$/.test(value)) {
        throw new WorkloadError('environment_invalid');
    }

    const integer = Number(value);

    if (!Number.isSafeInteger(integer) || integer < minimum || integer > maximum) {
        throw new WorkloadError('environment_invalid');
    }

    return integer;
}

async function readJsonFile(path) {
    try {
        return JSON.parse(await readFile(path, 'utf8'));
    } catch {
        throw new WorkloadError('private_file_invalid');
    }
}

function countStatus(counts, status) {
    const key = String(status);
    counts[key] = (counts[key] ?? 0) + 1;
}

function countValues(values) {
    const counts = {};

    for (const value of values) {
        if (typeof value === 'string' && value !== '') {
            counts[value] = (counts[value] ?? 0) + 1;
        }
    }

    return sortObject(counts);
}

function sortObject(value) {
    return Object.fromEntries(Object.entries(value).sort(
        ([left], [right]) => left.localeCompare(right),
    ));
}

function splitSetCookieHeader(value) {
    if (!value) {
        return [];
    }

    return value.split(/,(?=\s*[^;,=]+=[^;,]+)/);
}

function delay(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    await main();
}
