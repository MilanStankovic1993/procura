import assert from 'node:assert/strict';
import test from 'node:test';
import {
    buildReport,
    CookieJar,
    latencyPercentiles,
    validateWorkloadInputs,
    WorkloadError,
} from './analysis-pipeline-workload.mjs';

const budgets = {
    version: 'analysis-pipeline-workload-budget:v1',
    minimum_throughput_per_second: 0.25,
    maximum_draft_p95_milliseconds: 2000,
    maximum_submit_p95_milliseconds: 2000,
    maximum_pipeline_p95_milliseconds: 60000,
    maximum_failure_rate_basis_points: 0,
};

test('percentiles use a deterministic nearest-rank contract', () => {
    assert.deepEqual(latencyPercentiles([40, 10, 30, 20]), {
        p50: 20,
        p95: 40,
        p99: 40,
    });
    assert.deepEqual(latencyPercentiles([]), {
        p50: null,
        p95: null,
        p99: null,
    });
});

test('the cookie jar retains cookies and decodes only the CSRF value', () => {
    const jar = new CookieJar();
    jar.capture({
        getSetCookie: () => [
            'XSRF-TOKEN=csrf%20value; Path=/; Secure',
            'procura_session=session-secret; Path=/; HttpOnly; Secure',
        ],
    });

    assert.equal(jar.csrfToken(), 'csrf value');
    assert.match(jar.header(), /XSRF-TOKEN=csrf%20value/);
    assert.match(jar.header(), /procura_session=session-secret/);
});

test('private workload inputs require exact bounded actors and unique scenarios', () => {
    const permit = {
        version: 1,
        permit: 'a'.repeat(43),
        expires_at: new Date(Date.now() + 60_000).toISOString(),
        actors: 1,
        scenarios: 1,
        mutation_requests: 2,
        evidence_eligible: true,
        budgets,
    };
    const accounts = {
        actors: [{
            key: 'actor-1',
            email: 'load@example.test',
            password: 'never-output-this',
        }],
    };
    const scenarios = {
        scenarios: [{
            actor: 'actor-1',
            listing_id: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            target_country_code: 'DE',
        }],
    };

    assert.deepEqual(validateWorkloadInputs(permit, accounts, scenarios), {
        actors: accounts.actors,
        scenarios: scenarios.scenarios,
    });

    assert.throws(
        () => validateWorkloadInputs(permit, accounts, {
            scenarios: [scenarios.scenarios[0], scenarios.scenarios[0]],
        }),
        (error) => error instanceof WorkloadError
            && error.code === 'scenarios_file_invalid',
    );
});

test('the report is aggregate-only and fails closed on rate limiting', () => {
    const report = buildReport({
        startedAt: 1_000,
        finishedAt: 3_000,
        expectedScenarios: 1,
        actorCount: 1,
        concurrency: 1,
        results: [{
            success: true,
            draftMilliseconds: [10],
            submitMilliseconds: [20],
            pipelineMilliseconds: [500],
            terminalStatus: 'completed',
            password: 'never-output-this',
            listingId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        }],
        httpStatusCounts: { 201: 1, 202: 1, 429: 1 },
        budgets,
        evidenceEligible: true,
    });
    const serialized = JSON.stringify(report);

    assert.equal(report.status, 'failed');
    assert.deepEqual(report.violations, ['rate_limit']);
    assert.doesNotMatch(serialized, /never-output-this/);
    assert.doesNotMatch(serialized, /01ARZ3NDEKTSV4RRFFQ69G5FAV/);
});

test('a fake-provider rehearsal can never become release evidence', () => {
    const report = buildReport({
        startedAt: 1_000,
        finishedAt: 2_000,
        expectedScenarios: 1,
        actorCount: 1,
        concurrency: 1,
        results: [{
            success: true,
            draftMilliseconds: [10],
            submitMilliseconds: [20],
            pipelineMilliseconds: [500],
            terminalStatus: 'needs_input',
        }],
        httpStatusCounts: { 201: 1, 202: 1, 200: 1 },
        budgets,
        evidenceEligible: false,
    });

    assert.equal(report.status, 'failed');
    assert.equal(report.evidence_eligible, false);
    assert.deepEqual(report.violations, ['production_shaped_providers']);
});
