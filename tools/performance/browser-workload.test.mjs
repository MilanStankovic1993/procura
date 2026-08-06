import assert from "node:assert/strict";
import test from "node:test";

import {
    buildReport,
    contractHash,
    percentile,
    validateInputs,
} from "./browser-workload.mjs";

function permit(overrides = {}) {
    const value = {
        version: 1,
        origin: "https://staging.example.test",
        actors: 1,
        samples_per_scenario: 20,
        minimum_evidence_samples_per_scenario: 20,
        scenarios: ["overview", "buy_index", "sell_index"],
        budgets: {
            version: "browser-workload-budget:v1",
            maximum_document_ttfb_p95_milliseconds: 1000,
            maximum_route_ready_p95_milliseconds: 4000,
            maximum_lcp_p95_milliseconds: 2500,
            maximum_cls_p95: 0.1,
            maximum_failure_rate_basis_points: 0,
        },
        profile: {
            version: "browser-desktop-profile:v1",
            viewport_width: 1440,
            viewport_height: 900,
            cpu_slowdown_rate: 4,
            network_latency_milliseconds: 40,
            download_bits_per_second: 10_000_000,
            upload_bits_per_second: 2_000_000,
        },
        permit: "a".repeat(43),
        expires_at: "2099-01-01T00:00:00Z",
        authorizations: 60,
        evidence_eligible: true,
        ...overrides,
    };
    value.contract_hash = contractHash(value);

    return value;
}

const accounts = {
    version: 1,
    actors: [
        {
            key: "owner_1",
            email: "browser-owner@example.test",
            password: "private-password",
        },
    ],
};

function successfulResults() {
    return ["overview", "buy_index", "sell_index"].flatMap((scenario) =>
        Array.from({ length: 20 }, (_, index) => ({
            scenario,
            success: true,
            documentTtfbMilliseconds: index % 2 === 0 ? 100 : 200,
            routeReadyMilliseconds: index % 2 === 0 ? 900 : 1000,
            lcpMilliseconds: index % 2 === 0 ? 700 : 800,
            cls: index % 2 === 0 ? 0.01 : 0.02,
        })),
    );
}

test("nearest-rank browser percentiles are deterministic", () => {
    assert.equal(percentile([40, 10, 30, 20], 50), 20);
    assert.equal(percentile([40, 10, 30, 20], 95), 40);
    assert.equal(percentile([], 95), null);
});

test("the permit is origin, scenario, actor, and contract bound", () => {
    assert.equal(
        contractHash(permit()),
        "40c8b2d7e7bdbccc218a7cdc2014eca50e6bf859613a710f5d1ea6a3cf4c0dcf",
    );
    assert.equal(
        validateInputs(permit(), accounts, "https://staging.example.test")
            .actors.length,
        1,
    );

    assert.throws(
        () =>
            validateInputs(
                permit(),
                accounts,
                "https://production.example.test",
            ),
        { message: "permit_file_invalid" },
    );

    const weakened = permit();
    weakened.budgets.maximum_lcp_p95_milliseconds = 999_999;
    assert.throws(
        () =>
            validateInputs(weakened, accounts, "https://staging.example.test"),
        { message: "permit_file_invalid" },
    );

    const rehearsal = permit({
        samples_per_scenario: 2,
        authorizations: 6,
        evidence_eligible: false,
    });
    assert.equal(
        validateInputs(rehearsal, accounts, "https://staging.example.test")
            .permit.evidence_eligible,
        false,
    );
    rehearsal.evidence_eligible = true;
    assert.throws(
        () =>
            validateInputs(rehearsal, accounts, "https://staging.example.test"),
        { message: "permit_file_invalid" },
    );
});

test("a complete browser run emits only aggregate passing evidence", () => {
    const report = buildReport({
        permit: permit(),
        startedAt: Date.parse("2026-08-06T10:00:00Z"),
        finishedAt: Date.parse("2026-08-06T10:01:00Z"),
        browserMajorVersion: "140",
        results: successfulResults(),
        httpStatusCounts: { 200: 24 },
    });
    const output = JSON.stringify(report);

    assert.equal(report.status, "passed");
    assert.equal(
        report.scenarios.overview.metrics.route_ready_milliseconds.p95,
        1000,
    );
    assert.equal(report.samples.completed, 60);
    assert.equal(output.includes("browser-owner@example.test"), false);
    assert.equal(output.includes("private-password"), false);
    assert.equal(output.includes("staging.example.test"), false);
    assert.equal(output.includes("X-Procura-Browser-Workload-Permit"), false);
});

test("undersampling, failures, HTTP errors, and p95 regressions fail the report", () => {
    const results = successfulResults();
    results[0] = {
        scenario: "overview",
        success: false,
        failureCode: "console_error",
    };
    results[20].routeReadyMilliseconds = 5000;
    results[21].routeReadyMilliseconds = 5000;
    const inputPermit = permit({ evidence_eligible: false });
    inputPermit.contract_hash = contractHash(inputPermit);
    const report = buildReport({
        permit: inputPermit,
        startedAt: 1000,
        finishedAt: 2000,
        browserMajorVersion: "140",
        results,
        httpStatusCounts: { 200: 20, 500: 1 },
    });

    assert.equal(report.status, "failed");
    assert.equal(report.failure_code_counts.console_error, 1);
    assert.ok(report.violations.includes("evidence_eligibility"));
    assert.ok(report.violations.includes("sample_completion"));
    assert.ok(report.violations.includes("http_error"));
    assert.ok(report.violations.includes("buy_index:route_ready_p95"));
});
