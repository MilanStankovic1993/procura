<?php

use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Analyses\AiAnalysisStatus;
use App\Enums\Analyses\AiValidationStatus;
use App\Enums\Analyses\AnalysisPipelineProviderScope;
use App\Enums\Analyses\AnalysisPipelineStage;
use App\Enums\Analyses\AnalysisStatus;
use App\Enums\Analyses\AnalysisType;
use App\Models\AiAnalysis;
use App\Models\Analysis;
use App\Models\AnalysisPipelineMetric;
use App\Models\Listing;
use App\Models\ListingSnapshot;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    config()->set('performance.analysis_pipeline_metrics.enabled', true);
    config()->set(
        'performance.analysis_pipeline_metrics.default_minimum_samples',
        1,
    );
    app(SyncMarketReferenceData::class)->sync();

    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $listing = Listing::factory()->create([
        'organization_id' => $organization,
        'created_by_user_id' => $user,
    ]);
    $snapshot = ListingSnapshot::query()->create([
        'listing_id' => $listing->getKey(),
        'sequence' => 1,
        'captured_by_user_id' => $user->getKey(),
        'captured_at' => now(),
        'source_url' => $listing->source_url,
        'external_id' => $listing->external_id,
        'marketplace_name' => $listing->marketplace_name,
        'marketplace_key' => $listing->marketplace_key,
        'title' => $listing->title,
        'description' => $listing->description,
        'asking_price_minor' => $listing->asking_price_minor,
        'currency_code' => $listing->currency_code,
        'seller_information' => $listing->seller_information,
        'location' => $listing->location,
        'source_country_code' => $listing->source_country_code,
        'target_country_code' => $listing->target_country_code,
        'status' => $listing->status,
        'notes' => $listing->notes,
        'raw_payload' => [],
        'content_hash' => hash('sha256', 'pipeline-stage-metric-snapshot'),
    ]);
    $this->metricAnalysis = Analysis::query()->create([
        'organization_id' => $organization->getKey(),
        'listing_id' => $listing->getKey(),
        'listing_snapshot_id' => $snapshot->getKey(),
        'requested_by_user_id' => $user->getKey(),
        'analysis_type' => AnalysisType::Buy,
        'status' => AnalysisStatus::Completed,
        'source_country_code' => $listing->source_country_code,
        'target_country_code' => $listing->target_country_code,
        'pipeline_version' => 'buy-analysis-pipeline:v1',
        'request_payload' => [],
        'request_hash' => hash('sha256', 'pipeline-stage-metric-request'),
        'result_payload' => [],
        'processing_attempts' => 0,
        'submitted_at' => now(),
        'finished_at' => now(),
    ]);
});

function createPipelineStageMetric(
    Analysis $analysis,
    int $durationMicroseconds = 12_000,
    AnalysisPipelineProviderScope $providerScope = AnalysisPipelineProviderScope::ProductionShaped,
    ?DateTimeInterface $recordedAt = null,
    AiAnalysisStatus $status = AiAnalysisStatus::Completed,
    ?AnalysisPipelineStage $failedStage = null,
): AnalysisPipelineMetric {
    $attemptNumber = AiAnalysis::query()
        ->where('analysis_id', $analysis->getKey())
        ->count() + 1;
    $attempt = AiAnalysis::query()->create([
        'organization_id' => $analysis->organization_id,
        'analysis_id' => $analysis->getKey(),
        'attempt_number' => $attemptNumber,
        'status' => $status,
        'provider' => 'test-provider',
        'model' => 'test-model',
        'prompt_version' => 'test-prompt:v1',
        'input_hash' => $analysis->request_hash,
        'input_snapshot' => [],
        'result_json' => $status === AiAnalysisStatus::Completed ? [] : null,
        'validation_status' => $status === AiAnalysisStatus::Completed
            ? AiValidationStatus::Valid
            : AiValidationStatus::Invalid,
        'started_at' => now(),
        'completed_at' => now(),
        'error' => $status === AiAnalysisStatus::Failed
            ? 'Bounded fixture failure.'
            : null,
    ]);

    return AnalysisPipelineMetric::query()->create([
        'ai_analysis_id' => $attempt->getKey(),
        'attempt_number' => $attemptNumber,
        'pipeline_version' => $analysis->pipeline_version,
        'provider_scope' => $providerScope,
        'attempt_status' => $status,
        'failed_stage' => $failedStage,
        'provider_analysis_microseconds' => $durationMicroseconds,
        'product_matching_microseconds' => $durationMicroseconds,
        'comparable_selection_microseconds' => $durationMicroseconds,
        'price_estimation_microseconds' => $durationMicroseconds,
        'risk_assessment_microseconds' => $durationMicroseconds,
        'finalization_microseconds' => $durationMicroseconds,
        'total_microseconds' => $durationMicroseconds * 6,
        'recorded_at' => $recordedAt ?? now(),
    ]);
}

test('staging emits passing bounded identifier-free stage evidence', function () {
    $metric = createPipelineStageMetric($this->metricAnalysis);
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'staging');

    try {
        $exitCode = Artisan::call('operations:analysis-pipeline-stage-metrics', [
            '--window' => '60',
            '--limit' => '10',
            '--minimum-samples' => '1',
            '--expected-samples' => '1',
            '--json' => true,
        ]);
        $output = trim(Artisan::output());
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    } finally {
        $application->detectEnvironment(
            fn (): string => $originalEnvironment,
        );
    }

    expect($exitCode)->toBe(0)
        ->and($report['status'])->toBe('passed')
        ->and($report['release_evidence'])->toBeTrue()
        ->and($report['samples'])->toBe(1)
        ->and($report['expected_samples'])->toBe(1)
        ->and($report['sample_count_status'])->toBe('pass')
        ->and($report['truncated'])->toBeFalse()
        ->and($report['budget_version'])
        ->toBe('analysis-pipeline-stage-budget:v1')
        ->and($report['stages']['provider_analysis']['p95_milliseconds'])
        ->toBe(12)
        ->and($report['total']['p99_milliseconds'])->toBe(72)
        ->and(substr_count($output, PHP_EOL))->toBe(0)
        ->and($output)->not->toContain($metric->getKey())
        ->and($output)->not->toContain($metric->ai_analysis_id)
        ->and($output)->not->toContain($this->metricAnalysis->getKey());
})->group('capacity');

test('rehearsal providers and truncated samples cannot become release evidence', function () {
    createPipelineStageMetric(
        $this->metricAnalysis,
        providerScope: AnalysisPipelineProviderScope::Rehearsal,
    );
    createPipelineStageMetric($this->metricAnalysis);
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'staging');

    try {
        $exitCode = Artisan::call('operations:analysis-pipeline-stage-metrics', [
            '--limit' => '1',
            '--minimum-samples' => '1',
            '--expected-samples' => '1',
            '--json' => true,
        ]);
        $report = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    } finally {
        $application->detectEnvironment(
            fn (): string => $originalEnvironment,
        );
    }

    expect($exitCode)->toBe(1)
        ->and($report['status'])->toBe('failed')
        ->and($report['release_evidence'])->toBeFalse()
        ->and($report['samples'])->toBe(1)
        ->and($report['truncated'])->toBeTrue();
})->group('capacity');

test('production is forbidden and local reports require an explicit rehearsal flag', function () {
    createPipelineStageMetric($this->metricAnalysis);
    $application = app();
    $originalEnvironment = $application->environment();

    try {
        $localExit = Artisan::call('operations:analysis-pipeline-stage-metrics', [
            '--json' => true,
        ]);
        $localReport = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $application->detectEnvironment(fn (): string => 'production');
        $productionExit = Artisan::call(
            'operations:analysis-pipeline-stage-metrics',
            [
                '--allow-local-rehearsal' => true,
                '--json' => true,
            ],
        );
        $productionReport = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    } finally {
        $application->detectEnvironment(
            fn (): string => $originalEnvironment,
        );
    }

    expect($localExit)->toBe(1)
        ->and($localReport['error_code'])->toBe('staging_environment_required')
        ->and($productionExit)->toBe(1)
        ->and($productionReport['error_code'])->toBe('production_forbidden');
})->group('capacity');

test('the minimum sample option can tighten but never weaken the repository baseline', function () {
    config()->set(
        'performance.analysis_pipeline_metrics.default_minimum_samples',
        20,
    );
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'staging');

    try {
        $exitCode = Artisan::call('operations:analysis-pipeline-stage-metrics', [
            '--minimum-samples' => '1',
            '--json' => true,
        ]);
        $report = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    } finally {
        $application->detectEnvironment(
            fn (): string => $originalEnvironment,
        );
    }

    expect($exitCode)->toBe(1)
        ->and($report)->toBe([
            'status' => 'failed',
            'error_code' => 'invalid_configuration',
        ]);
})->group('capacity');

test('staging evidence requires an exact expected attempt count', function () {
    createPipelineStageMetric($this->metricAnalysis);
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'staging');

    try {
        $exitCode = Artisan::call('operations:analysis-pipeline-stage-metrics', [
            '--minimum-samples' => '1',
            '--json' => true,
        ]);
        $report = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    } finally {
        $application->detectEnvironment(
            fn (): string => $originalEnvironment,
        );
    }

    expect($exitCode)->toBe(1)
        ->and($report['error_code'])->toBe('invalid_configuration');
})->group('capacity');

test('retention purge is batch bounded and bypasses no business evidence', function () {
    $oldest = createPipelineStageMetric(
        $this->metricAnalysis,
        recordedAt: now()->subDays(32),
    );
    $older = createPipelineStageMetric(
        $this->metricAnalysis,
        recordedAt: now()->subDays(31),
    );
    $fresh = createPipelineStageMetric($this->metricAnalysis);

    $exitCode = Artisan::call('operations:purge-analysis-pipeline-metrics', [
        '--limit' => '1',
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())
        ->toContain('Purged 1 expired Analysis pipeline metric rows.')
        ->and(AnalysisPipelineMetric::query()->find($oldest->getKey()))->toBeNull()
        ->and(AnalysisPipelineMetric::query()->find($older->getKey()))->not->toBeNull()
        ->and(AnalysisPipelineMetric::query()->find($fresh->getKey()))->not->toBeNull()
        ->and(AiAnalysis::query()->count())->toBe(3)
        ->and(Analysis::query()->count())->toBe(1);
});

test('metric rows are immutable outside the retention purge', function () {
    $metric = createPipelineStageMetric($this->metricAnalysis);

    expect(fn () => $metric->update(['total_microseconds' => 1]))
        ->toThrow(LogicException::class, 'Analysis pipeline metrics are immutable.')
        ->and(fn () => $metric->delete())
        ->toThrow(
            LogicException::class,
            'Analysis pipeline metrics may only be removed by the retention purge.',
        );
});
