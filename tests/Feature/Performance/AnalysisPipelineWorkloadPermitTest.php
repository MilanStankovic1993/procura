<?php

use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Analysis;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Operations\Capacity\AnalysisPipelineWorkloadPermitStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('performance.analysis_pipeline_workload.enabled', true);
    config()->set(
        'performance.analysis_pipeline_workload.cache_store',
        'array',
    );
    config()->set('analyses.submission_enabled', true);
    Cache::store('array')->flush();
});

/**
 * @return array{User, Organization}
 */
function analysisWorkloadActor(
    OrganizationRole $role = OrganizationRole::Analyst,
): array {
    $actor = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $actor,
        'role' => $role,
    ]);
    $actor->forceFill([
        'current_organization_id' => $organization->getKey(),
    ])->save();

    return [$actor->fresh(), $organization];
}

test('ordinary analysis traffic remains unchanged when no workload header exists', function () {
    [$actor] = analysisWorkloadActor();

    $this->actingAs($actor)
        ->postJson(route('api.v1.buy-analyses.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['listing_id', 'target_country_code']);
})->group('capacity');

test('a permit is actor-bound and consumed once for every analysis mutation', function () {
    [$permittedActor] = analysisWorkloadActor();
    [$otherActor] = analysisWorkloadActor();
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'staging');

    try {
        $permit = app(AnalysisPipelineWorkloadPermitStore::class)->issue(
            [$permittedActor],
            scenarios: 1,
            ttlSeconds: 60,
        );
        $header = config(
            'performance.analysis_pipeline_workload.header',
        );

        $this->actingAs($otherActor)
            ->withHeader($header, $permit['token'])
            ->postJson(route('api.v1.buy-analyses.store'), [])
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'analysis_workload_permit_rejected',
            );

        for ($request = 1; $request <= 2; $request++) {
            $this->actingAs($permittedActor)
                ->withHeader($header, $permit['token'])
                ->postJson(route('api.v1.buy-analyses.store'), [])
                ->assertUnprocessable();
        }

        $this->actingAs($permittedActor)
            ->withHeader($header, $permit['token'])
            ->postJson(route('api.v1.buy-analyses.store'), [])
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'analysis_workload_permit_rejected',
            );
    } finally {
        $application->detectEnvironment(
            fn (): string => $originalEnvironment,
        );
    }
})->group('capacity');

test('production permanently rejects a workload permit before validation or mutation', function () {
    [$actor] = analysisWorkloadActor();
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'production');

    try {
        $response = $this->actingAs($actor)
            ->withHeader(
                config('performance.analysis_pipeline_workload.header'),
                str_repeat('a', 43),
            )
            ->postJson(route('api.v1.buy-analyses.store'), []);
    } finally {
        $application->detectEnvironment(
            fn (): string => $originalEnvironment,
        );
    }

    $response->assertForbidden()
        ->assertJsonPath('code', 'analysis_workload_permit_rejected');
    expect(Analysis::query()->count())->toBe(0);
})->group('capacity');

test('the permit command is staging-only and writes the secret outside git', function () {
    [$actor] = analysisWorkloadActor();
    $actor->load('memberships');
    $membership = $actor->memberships->firstWhere(
        'organization_id',
        $actor->current_organization_id,
    );
    expect($actor->hasVerifiedEmail())->toBeTrue()
        ->and($actor->current_organization_id)->not->toBeNull()
        ->and($membership)->not->toBeNull()
        ->and($membership->role->allows(OrganizationPermission::ManageAnalyses))
        ->toBeTrue();
    $application = app();
    $originalEnvironment = $application->environment();
    $cache = app(CacheManager::class);
    $cache->store('array');
    config()->set('cache.stores.array.driver', 'redis');
    config()->set('queue.default', 'redis');
    $application->detectEnvironment(fn (): string => 'staging');
    $absolutePath = null;

    try {
        $blockedExitCode = Artisan::call(
            'operations:issue-analysis-workload-permit',
            [
                '--actor-email' => [$actor->email],
                '--scenarios' => '1',
                '--ttl' => '60',
                '--acknowledge-load' => true,
                '--json' => true,
            ],
        );
        $blockedPayload = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        expect($blockedExitCode)->toBe(1)
            ->and($blockedPayload)->toBe([
                'status' => 'failed',
                'error_code' => 'invalid_provider_scope',
            ]);

        $exitCode = Artisan::call(
            'operations:issue-analysis-workload-permit',
            [
                '--actor-email' => [$actor->email],
                '--scenarios' => '1',
                '--ttl' => '60',
                '--acknowledge-load' => true,
                '--allow-fake-provider-rehearsal' => true,
                '--json' => true,
            ],
        );
        $output = trim(Artisan::output());
        $payload = json_decode(
            $output,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        expect($payload['status'])->toBe('issued');
        $absolutePath = base_path(str_replace('/', DIRECTORY_SEPARATOR, $payload['permit_file']));
        $privatePermit = json_decode(
            file_get_contents($absolutePath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($exitCode)->toBe(0)
            ->and($payload['status'])->toBe('issued')
            ->and($payload['actors'])->toBe(1)
            ->and($payload['scenarios'])->toBe(1)
            ->and($payload['mutation_requests'])->toBe(2)
            ->and($privatePermit['permit'])->toHaveLength(43)
            ->and($privatePermit['budgets']['version'])
            ->toBe('analysis-pipeline-workload-budget:v1')
            ->and($privatePermit['evidence_eligible'])->toBeFalse()
            ->and($output)->not->toContain($privatePermit['permit']);
    } finally {
        if (is_string($absolutePath) && is_file($absolutePath)) {
            unlink($absolutePath);
        }

        $application->detectEnvironment(
            fn (): string => $originalEnvironment,
        );
    }
})->group('capacity');

test('the permit command cannot be overridden in production', function () {
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'production');

    try {
        $exitCode = Artisan::call(
            'operations:issue-analysis-workload-permit',
            [
                '--actor-email' => ['actor@example.test'],
                '--acknowledge-load' => true,
                '--json' => true,
            ],
        );
        $payload = json_decode(
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
        ->and($payload)->toBe([
            'status' => 'failed',
            'error_code' => 'production_forbidden',
        ]);
})->group('capacity');
