import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";
import { createRequire } from "node:module";
import { pathToFileURL } from "node:url";
import process from "node:process";

const REPORT_VERSION = "browser-workload-report:v1";
const MAXIMUM_FILE_BYTES = 1024 * 1024;
const DEFAULT_NAVIGATION_TIMEOUT_MS = 30_000;
const MAXIMUM_NAVIGATION_TIMEOUT_MS = 120_000;
const ACTOR_SAMPLE_INTERVAL_MS = 8_000;
const OBSERVATION_SETTLE_MS = 500;
const PERMIT_HEADER = "X-Procura-Browser-Workload-Permit";
const MINIMUM_EVIDENCE_SAMPLES = 20;

const SCENARIOS = Object.freeze({
    overview: Object.freeze({
        path: "/app/overview",
        readySelector:
            '[data-performance-page="overview"][data-performance-state="ready"]',
        errorSelector:
            '[data-performance-page="overview"][data-performance-state="error"]',
    }),
    buy_index: Object.freeze({
        path: "/app/buy",
        readySelector:
            '[data-performance-page="buy_index"][data-performance-state="ready"]',
        errorSelector:
            '[data-performance-page="buy_index"][data-performance-state="error"]',
    }),
    sell_index: Object.freeze({
        path: "/app/sell",
        readySelector:
            '[data-performance-page="sell_index"][data-performance-state="ready"]',
        errorSelector:
            '[data-performance-page="sell_index"][data-performance-state="error"]',
    }),
});

export class BrowserWorkloadError extends Error {
    constructor(code) {
        super(code);
        this.name = "BrowserWorkloadError";
        this.code = code;
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

    return rounded(sorted[index]);
}

export function percentiles(values) {
    return {
        p50: percentile(values, 50),
        p95: percentile(values, 95),
        p99: percentile(values, 99),
    };
}

export function contractHash(permit) {
    const contract = {
        version: permit?.version,
        origin: permit?.origin,
        actors: permit?.actors,
        samples_per_scenario: permit?.samples_per_scenario,
        minimum_evidence_samples_per_scenario:
            permit?.minimum_evidence_samples_per_scenario,
        scenarios: permit?.scenarios,
        budgets: permit?.budgets,
        profile: permit?.profile,
        evidence_eligible: permit?.evidence_eligible,
    };

    return createHash("sha256").update(JSON.stringify(contract)).digest("hex");
}

export function validateInputs(
    permit,
    accounts,
    requestedOrigin,
    now = Date.now(),
) {
    const scenarioKeys = Object.keys(SCENARIOS);

    if (
        permit?.version !== 1 ||
        !validOrigin(permit.origin) ||
        permit.origin !== requestedOrigin ||
        typeof permit.permit !== "string" ||
        !/^[A-Za-z0-9_-]{43}$/.test(permit.permit) ||
        typeof permit.contract_hash !== "string" ||
        !/^[a-f0-9]{64}$/.test(permit.contract_hash) ||
        permit.contract_hash !== contractHash(permit) ||
        !Number.isInteger(permit.actors) ||
        permit.actors < 1 ||
        permit.actors > 20 ||
        !Number.isInteger(permit.samples_per_scenario) ||
        permit.samples_per_scenario < 1 ||
        permit.samples_per_scenario > 100 ||
        permit.minimum_evidence_samples_per_scenario !==
            MINIMUM_EVIDENCE_SAMPLES ||
        !Array.isArray(permit.scenarios) ||
        JSON.stringify(permit.scenarios) !== JSON.stringify(scenarioKeys) ||
        !Number.isInteger(permit.authorizations) ||
        permit.authorizations !==
            permit.samples_per_scenario * scenarioKeys.length ||
        Date.parse(permit.expires_at) <= now ||
        permit.evidence_eligible !==
            permit.samples_per_scenario >=
                permit.minimum_evidence_samples_per_scenario ||
        !validBudgets(permit.budgets) ||
        !validProfile(permit.profile)
    ) {
        throw new BrowserWorkloadError("permit_file_invalid");
    }

    const actors = accounts?.actors;

    if (
        accounts?.version !== 1 ||
        !Array.isArray(actors) ||
        actors.length !== permit.actors ||
        actors.length < 1 ||
        actors.length > 20
    ) {
        throw new BrowserWorkloadError("accounts_file_invalid");
    }

    const keys = new Set();
    const emails = new Set();

    for (const actor of actors) {
        const email =
            typeof actor?.email === "string" ? actor.email.toLowerCase() : "";

        if (
            typeof actor?.key !== "string" ||
            !/^[a-zA-Z0-9_-]{1,64}$/.test(actor.key) ||
            keys.has(actor.key) ||
            typeof actor.email !== "string" ||
            !actor.email.includes("@") ||
            emails.has(email) ||
            typeof actor.password !== "string" ||
            actor.password.length < 1
        ) {
            throw new BrowserWorkloadError("accounts_file_invalid");
        }

        keys.add(actor.key);
        emails.add(email);
    }

    return { permit, actors };
}

export function buildReport({
    permit,
    startedAt,
    finishedAt,
    browserMajorVersion,
    results,
    httpStatusCounts,
}) {
    const expectedPerScenario = permit.samples_per_scenario;
    const expectedTotal = permit.authorizations;
    const successful = results.filter((result) => result.success);
    const failed = expectedTotal - successful.length;
    const failureRateBasisPoints = Math.round(
        (failed / expectedTotal) * 10_000,
    );
    const violations = [];

    if (!permit.evidence_eligible) {
        violations.push("evidence_eligibility");
    }

    if (
        results.length !== expectedTotal ||
        successful.length !== expectedTotal
    ) {
        violations.push("sample_completion");
    }

    if (
        failureRateBasisPoints >
        permit.budgets.maximum_failure_rate_basis_points
    ) {
        violations.push("failure_rate");
    }

    if (
        Object.entries(httpStatusCounts).some(
            ([status, count]) => Number(status) >= 400 && count > 0,
        )
    ) {
        violations.push("http_error");
    }

    const scenarioReports = {};

    for (const scenario of permit.scenarios) {
        const scenarioResults = results.filter(
            (result) => result.scenario === scenario,
        );
        const scenarioSuccessful = scenarioResults.filter(
            (result) => result.success,
        );
        const metrics = {
            document_ttfb_milliseconds: percentiles(
                scenarioSuccessful.map(
                    (result) => result.documentTtfbMilliseconds,
                ),
            ),
            route_ready_milliseconds: percentiles(
                scenarioSuccessful.map(
                    (result) => result.routeReadyMilliseconds,
                ),
            ),
            lcp_milliseconds: percentiles(
                scenarioSuccessful.map((result) => result.lcpMilliseconds),
            ),
            cls: percentiles(scenarioSuccessful.map((result) => result.cls)),
        };

        scenarioReports[scenario] = {
            expected: expectedPerScenario,
            completed: scenarioSuccessful.length,
            failed: expectedPerScenario - scenarioSuccessful.length,
            metrics,
        };

        if (
            scenarioResults.length !== expectedPerScenario ||
            scenarioSuccessful.length !== expectedPerScenario
        ) {
            violations.push(`${scenario}:completion`);
        }

        if (
            metrics.document_ttfb_milliseconds.p95 >
            permit.budgets.maximum_document_ttfb_p95_milliseconds
        ) {
            violations.push(`${scenario}:document_ttfb_p95`);
        }

        if (
            metrics.route_ready_milliseconds.p95 >
            permit.budgets.maximum_route_ready_p95_milliseconds
        ) {
            violations.push(`${scenario}:route_ready_p95`);
        }

        if (
            metrics.lcp_milliseconds.p95 >
            permit.budgets.maximum_lcp_p95_milliseconds
        ) {
            violations.push(`${scenario}:lcp_p95`);
        }

        if (metrics.cls.p95 > permit.budgets.maximum_cls_p95) {
            violations.push(`${scenario}:cls_p95`);
        }
    }

    return {
        contract_version: REPORT_VERSION,
        budget_version: permit.budgets.version,
        profile_version: permit.profile.version,
        browser_major_version: browserMajorVersion,
        evidence_eligible: permit.evidence_eligible,
        status: violations.length === 0 ? "passed" : "failed",
        started_at: new Date(startedAt).toISOString(),
        finished_at: new Date(finishedAt).toISOString(),
        duration_milliseconds: finishedAt - startedAt,
        actors: permit.actors,
        samples: {
            expected: expectedTotal,
            completed: successful.length,
            failed,
            per_scenario: expectedPerScenario,
        },
        failure_rate_basis_points: failureRateBasisPoints,
        scenarios: scenarioReports,
        failure_code_counts: countValues(
            results
                .filter((result) => !result.success)
                .map((result) => result.failureCode),
        ),
        http_status_counts: sortObject(httpStatusCounts),
        budgets: permit.budgets,
        violations: [...new Set(violations)],
    };
}

async function main() {
    let browser;

    try {
        const requestedOrigin = requiredOrigin("PROCURA_BROWSER_LOAD_BASE_URL");
        const [permit, accounts] = await Promise.all([
            readJsonFile(
                requiredEnvironment("PROCURA_BROWSER_LOAD_PERMIT_FILE"),
            ),
            readJsonFile(
                requiredEnvironment("PROCURA_BROWSER_LOAD_ACCOUNTS_FILE"),
            ),
        ]);
        const configuration = validateInputs(permit, accounts, requestedOrigin);
        const navigationTimeout = environmentInteger(
            "PROCURA_BROWSER_LOAD_NAVIGATION_TIMEOUT_MS",
            DEFAULT_NAVIGATION_TIMEOUT_MS,
            5_000,
            MAXIMUM_NAVIGATION_TIMEOUT_MS,
        );
        let chromium;

        try {
            const frontendRequire = createRequire(
                new URL("../../frontend/package.json", import.meta.url),
            );
            ({ chromium } = frontendRequire("playwright"));
        } catch {
            throw new BrowserWorkloadError("browser_runtime_unavailable");
        }

        browser = await chromium.launch({ headless: true });
        const browserMajorVersion = browser.version().split(".", 1)[0];
        const actorStates = await authenticateActors(
            browser,
            configuration.actors,
            requestedOrigin,
            navigationTimeout,
        );
        const tasks = buildTasks(permit, configuration.actors);
        const gates = new Map(
            configuration.actors.map((actor) => [actor.key, 0]),
        );
        const httpStatusCounts = {};
        const results = [];
        const startedAt = Date.now();

        for (const task of tasks) {
            const nextAt = gates.get(task.actor.key) ?? 0;

            if (nextAt > Date.now()) {
                await delay(nextAt - Date.now());
            }

            gates.set(task.actor.key, Date.now() + ACTOR_SAMPLE_INTERVAL_MS);
            results.push(
                await runSample({
                    browser,
                    origin: requestedOrigin,
                    permit,
                    task,
                    storageState: actorStates.get(task.actor.key),
                    navigationTimeout,
                    httpStatusCounts,
                }),
            );
        }

        const report = buildReport({
            permit,
            startedAt,
            finishedAt: Date.now(),
            browserMajorVersion,
            results,
            httpStatusCounts,
        });

        process.stdout.write(`${JSON.stringify(report)}\n`);
        process.exitCode = report.status === "passed" ? 0 : 1;
    } catch (error) {
        process.stdout.write(
            `${JSON.stringify({
                contract_version: REPORT_VERSION,
                status: "failed",
                error_code:
                    error instanceof BrowserWorkloadError
                        ? error.code
                        : "browser_workload_setup_failed",
            })}\n`,
        );
        process.exitCode = 1;
    } finally {
        await browser?.close().catch(() => undefined);
    }
}

async function authenticateActors(browser, actors, origin, timeout) {
    const states = new Map();

    for (const actor of actors) {
        const context = await browser.newContext({
            baseURL: origin,
            locale: "en-US",
        });

        try {
            const page = await context.newPage();
            await page.goto(`${origin}/login`, {
                waitUntil: "domcontentloaded",
                timeout,
            });
            await page.locator("#email").fill(actor.email);
            await page.locator("#password").fill(actor.password);
            await page.locator('button[type="submit"]').click();
            await page.waitForURL(
                (url) =>
                    url.origin === origin && url.pathname.startsWith("/app"),
                { timeout },
            );
            await page
                .locator('[data-performance-page="overview"]')
                .waitFor({ timeout });
            states.set(actor.key, await context.storageState());
        } catch {
            throw new BrowserWorkloadError("actor_authentication_failed");
        } finally {
            await context.close();
        }
    }

    return states;
}

function buildTasks(permit, actors) {
    const tasks = [];
    let actorIndex = 0;

    for (let sample = 0; sample < permit.samples_per_scenario; sample++) {
        for (const scenario of permit.scenarios) {
            tasks.push({
                scenario,
                actor: actors[actorIndex % actors.length],
            });
            actorIndex++;
        }
    }

    return tasks;
}

async function runSample({
    browser,
    origin,
    permit,
    task,
    storageState,
    navigationTimeout,
    httpStatusCounts,
}) {
    const result = { scenario: task.scenario, success: false };
    const scenario = SCENARIOS[task.scenario];
    const context = await browser.newContext({
        baseURL: origin,
        storageState,
        locale: "en-US",
        viewport: {
            width: permit.profile.viewport_width,
            height: permit.profile.viewport_height,
        },
    });

    try {
        await authorizeSample(context, origin, permit, task.scenario);
        const page = await context.newPage();
        let consoleErrors = 0;
        let pageErrors = 0;
        let requestFailures = 0;
        let apiErrors = 0;

        page.on("console", (message) => {
            if (message.type() === "error") {
                consoleErrors++;
            }
        });
        page.on("pageerror", () => pageErrors++);
        page.on("requestfailed", () => requestFailures++);
        page.on("response", (response) => {
            const url = new URL(response.url());

            if (url.origin === origin && url.pathname.startsWith("/api/")) {
                countStatus(httpStatusCounts, response.status());

                if (response.status() >= 400) {
                    apiErrors++;
                }
            }
        });
        await page.addInitScript(() => {
            window.__procuraBrowserMetrics = { lcp: null, cls: 0 };

            new PerformanceObserver((list) => {
                const entries = list.getEntries();
                const last = entries.at(-1);

                if (last) {
                    window.__procuraBrowserMetrics.lcp = last.startTime;
                }
            }).observe({ type: "largest-contentful-paint", buffered: true });

            new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    if (!entry.hadRecentInput) {
                        window.__procuraBrowserMetrics.cls += entry.value;
                    }
                }
            }).observe({ type: "layout-shift", buffered: true });
        });
        await applyProfile(context, page, permit.profile);
        const response = await page.goto(`${origin}${scenario.path}`, {
            waitUntil: "domcontentloaded",
            timeout: navigationTimeout,
        });

        if (
            response === null ||
            response.status() >= 400 ||
            new URL(page.url()).origin !== origin ||
            new URL(page.url()).pathname !== scenario.path
        ) {
            throw new BrowserWorkloadError("document_navigation_failed");
        }

        countStatus(httpStatusCounts, response.status());
        const readiness = await Promise.race([
            page
                .locator(scenario.readySelector)
                .waitFor({ timeout: navigationTimeout })
                .then(() => "ready"),
            page
                .locator(scenario.errorSelector)
                .waitFor({ timeout: navigationTimeout })
                .then(() => "error"),
        ]);

        if (readiness !== "ready") {
            throw new BrowserWorkloadError("page_state_error");
        }

        const routeReadyMilliseconds = await page.evaluate(() =>
            performance.now(),
        );
        await page.waitForTimeout(OBSERVATION_SETTLE_MS);
        const metrics = await page.evaluate(() => {
            const navigation = performance.getEntriesByType("navigation")[0];

            return {
                documentTtfbMilliseconds:
                    navigation?.responseStart - navigation?.requestStart,
                lcpMilliseconds: window.__procuraBrowserMetrics?.lcp,
                cls: window.__procuraBrowserMetrics?.cls,
            };
        });

        if (
            !validMetric(metrics.documentTtfbMilliseconds) ||
            !validMetric(routeReadyMilliseconds) ||
            !validMetric(metrics.lcpMilliseconds) ||
            !validMetric(metrics.cls) ||
            consoleErrors > 0 ||
            pageErrors > 0 ||
            requestFailures > 0 ||
            apiErrors > 0
        ) {
            throw new BrowserWorkloadError(
                consoleErrors > 0
                    ? "console_error"
                    : pageErrors > 0
                      ? "page_error"
                      : requestFailures > 0
                        ? "request_failed"
                        : apiErrors > 0
                          ? "api_error"
                          : "performance_metric_invalid",
            );
        }

        Object.assign(result, {
            success: true,
            documentTtfbMilliseconds: metrics.documentTtfbMilliseconds,
            routeReadyMilliseconds,
            lcpMilliseconds: metrics.lcpMilliseconds,
            cls: metrics.cls,
        });
    } catch (error) {
        result.failureCode =
            error instanceof BrowserWorkloadError
                ? error.code
                : "browser_sample_failed";
    } finally {
        await context.close();
    }

    return result;
}

async function authorizeSample(context, origin, permit, scenario) {
    const cookies = await context.cookies(origin);
    const csrfCookie = cookies.find((cookie) => cookie.name === "XSRF-TOKEN");

    if (!csrfCookie) {
        throw new BrowserWorkloadError("csrf_cookie_missing");
    }

    let csrfToken;

    try {
        csrfToken = decodeURIComponent(csrfCookie.value);
    } catch {
        throw new BrowserWorkloadError("csrf_cookie_invalid");
    }

    const response = await context.request.post(
        `${origin}/api/v1/operations/browser-workload/authorize`,
        {
            data: {
                scenario,
                contract_hash: permit.contract_hash,
            },
            failOnStatusCode: false,
            headers: {
                Accept: "application/json",
                "Accept-Language": "en",
                Origin: origin,
                Referer: `${origin}/`,
                "X-XSRF-TOKEN": csrfToken,
                [PERMIT_HEADER]: permit.permit,
            },
            timeout: 15_000,
        },
    );

    if (response.status() !== 204) {
        throw new BrowserWorkloadError("sample_authorization_failed");
    }
}

async function applyProfile(context, page, profile) {
    const session = await context.newCDPSession(page);
    await session.send("Network.enable");
    await session.send("Network.emulateNetworkConditions", {
        offline: false,
        latency: profile.network_latency_milliseconds,
        downloadThroughput: profile.download_bits_per_second / 8,
        uploadThroughput: profile.upload_bits_per_second / 8,
    });
    await session.send("Emulation.setCPUThrottlingRate", {
        rate: profile.cpu_slowdown_rate,
    });
}

function validBudgets(budgets) {
    return (
        budgets?.version === "browser-workload-budget:v1" &&
        positiveInteger(budgets.maximum_document_ttfb_p95_milliseconds) &&
        budgets.maximum_document_ttfb_p95_milliseconds <= 1000 &&
        positiveInteger(budgets.maximum_route_ready_p95_milliseconds) &&
        budgets.maximum_route_ready_p95_milliseconds <= 4000 &&
        positiveInteger(budgets.maximum_lcp_p95_milliseconds) &&
        budgets.maximum_lcp_p95_milliseconds <= 2500 &&
        Number.isFinite(budgets.maximum_cls_p95) &&
        budgets.maximum_cls_p95 > 0 &&
        budgets.maximum_cls_p95 <= 0.1 &&
        budgets.maximum_failure_rate_basis_points === 0
    );
}

function validProfile(profile) {
    return (
        profile?.version === "browser-desktop-profile:v1" &&
        profile.viewport_width === 1440 &&
        profile.viewport_height === 900 &&
        profile.cpu_slowdown_rate === 4 &&
        profile.network_latency_milliseconds === 40 &&
        profile.download_bits_per_second === 10_000_000 &&
        profile.upload_bits_per_second === 2_000_000
    );
}

function validOrigin(value) {
    try {
        const url = new URL(value);

        return (
            url.protocol === "https:" &&
            url.username === "" &&
            url.password === "" &&
            url.pathname === "/" &&
            url.search === "" &&
            url.hash === "" &&
            url.origin === value
        );
    } catch {
        return false;
    }
}

function requiredOrigin(name) {
    const origin = requiredEnvironment(name);

    if (!validOrigin(origin)) {
        throw new BrowserWorkloadError("base_url_invalid");
    }

    return origin;
}

async function readJsonFile(path) {
    let stat;
    let contents;

    try {
        const file = await import("node:fs/promises");
        stat = await file.stat(path);

        if (!stat.isFile() || stat.size < 2 || stat.size > MAXIMUM_FILE_BYTES) {
            throw new Error("invalid file");
        }

        contents = await readFile(path, "utf8");
    } catch {
        throw new BrowserWorkloadError("private_file_unavailable");
    }

    try {
        return JSON.parse(contents);
    } catch {
        throw new BrowserWorkloadError("private_file_invalid");
    }
}

function requiredEnvironment(name) {
    const value = process.env[name];

    if (typeof value !== "string" || value === "" || value.trim() !== value) {
        throw new BrowserWorkloadError("environment_invalid");
    }

    return value;
}

function environmentInteger(name, fallback, minimum, maximum) {
    const value = process.env[name];

    if (value === undefined || value === "") {
        return fallback;
    }

    if (!/^[0-9]+$/.test(value)) {
        throw new BrowserWorkloadError("environment_invalid");
    }

    const integer = Number(value);

    if (
        !Number.isSafeInteger(integer) ||
        integer < minimum ||
        integer > maximum
    ) {
        throw new BrowserWorkloadError("environment_invalid");
    }

    return integer;
}

function positiveInteger(value) {
    return Number.isInteger(value) && value > 0;
}

function validMetric(value) {
    return Number.isFinite(value) && value >= 0;
}

function rounded(value) {
    return Math.round(value * 1000) / 1000;
}

function countStatus(counts, status) {
    const key = String(status);
    counts[key] = (counts[key] ?? 0) + 1;
}

function countValues(values) {
    const counts = {};

    for (const value of values) {
        if (typeof value === "string") {
            counts[value] = (counts[value] ?? 0) + 1;
        }
    }

    return sortObject(counts);
}

function sortObject(value) {
    return Object.fromEntries(
        Object.entries(value).sort(([left], [right]) =>
            left.localeCompare(right),
        ),
    );
}

function delay(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

if (
    process.argv[1] &&
    import.meta.url === pathToFileURL(process.argv[1]).href
) {
    await main();
}
