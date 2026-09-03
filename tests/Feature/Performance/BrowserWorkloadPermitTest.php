<?php

use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Operations\Capacity\BrowserWorkloadConfiguration;
use App\Operations\Capacity\BrowserWorkloadPermitStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('performance.browser_workload.enabled', true);
    config()->set('performance.browser_workload.cache_store', 'array');
    config()->set('app.frontend_url', 'https://staging.example.test');
    Cache::store('array')->flush();
});

/** @return array{User, Organization} */
function browserWorkloadActor(
    OrganizationRole $role = OrganizationRole::Viewer,
): array {
    $actor = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $actor,
        'role' => $role,
        'joined_at' => now(),
    ]);
    $actor->forceFill([
        'current_organization_id' => $organization->getKey(),
    ])->save();

    return [$actor->fresh(), $organization];
}

test('the browser permit is actor, scenario, contract, and sample bound', function () {
    [$permittedActor] = browserWorkloadActor();
    [$otherActor] = browserWorkloadActor();
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'staging');
    $configuration = app(BrowserWorkloadConfiguration::class);
    $contractHash = str_repeat('a', 64);

    try {
        $permit = app(BrowserWorkloadPermitStore::class)->issue(
            actors: [$permittedActor],
            scenarios: $configuration->scenarios(),
            samplesPerScenario: 1,
            ttlSeconds: 60,
            contractHash: $contractHash,
        );
        $header = $configuration->header();
        $route = route('api.v1.operations.browser-workload.authorize');

        $this->actingAs($otherActor)
            ->withHeader($header, $permit['token'])
            ->postJson($route, [
                'scenario' => 'overview',
                'contract_hash' => $contractHash,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'browser_workload_permit_rejected');

        $this->actingAs($permittedActor)
            ->withHeader($header, $permit['token'])
            ->postJson($route, [
                'scenario' => 'overview',
                'contract_hash' => str_repeat('b', 64),
            ])
            ->assertForbidden();

        $this->actingAs($permittedActor)
            ->withHeader($header, $permit['token'])
            ->postJson($route, [
                'scenario' => 'overview',
                'contract_hash' => $contractHash,
            ])
            ->assertNoContent();

        $this->actingAs($permittedActor)
            ->withHeader($header, $permit['token'])
            ->postJson($route, [
                'scenario' => 'overview',
                'contract_hash' => $contractHash,
            ])
            ->assertForbidden();

        $this->actingAs($permittedActor)
            ->withHeader($header, $permit['token'])
            ->postJson($route, [
                'scenario' => 'buy_index',
                'contract_hash' => $contractHash,
            ])
            ->assertNoContent();
    } finally {
        $application->detectEnvironment(fn (): string => $originalEnvironment);
    }
})->group('capacity');

test('missing or production browser workload permits are always rejected', function () {
    [$actor] = browserWorkloadActor();
    $route = route('api.v1.operations.browser-workload.authorize');

    $this->actingAs($actor)
        ->postJson($route, [
            'scenario' => 'overview',
            'contract_hash' => str_repeat('a', 64),
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'browser_workload_permit_rejected');

    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'production');

    try {
        $response = $this->actingAs($actor)
            ->withHeader(
                config('performance.browser_workload.header'),
                str_repeat('a', 43),
            )
            ->postJson($route, [
                'scenario' => 'overview',
                'contract_hash' => str_repeat('a', 64),
            ]);
    } finally {
        $application->detectEnvironment(fn (): string => $originalEnvironment);
    }

    $response->assertForbidden()
        ->assertJsonPath('code', 'browser_workload_permit_rejected');
})->group('capacity');

test('the browser permit command is staging only and writes a contract-bound private secret', function () {
    [$actor] = browserWorkloadActor(OrganizationRole::Owner);
    $application = app();
    $originalEnvironment = $application->environment();
    $cache = app(CacheManager::class);
    $cache->store('array');
    config()->set('cache.stores.array.driver', 'redis');
    config()->set('session.driver', 'redis');
    $application->detectEnvironment(fn (): string => 'staging');
    $absolutePath = null;

    try {
        $blockedExitCode = Artisan::call(
            'operations:issue-browser-workload-permit',
            [
                '--actor-email' => [$actor->email],
                '--samples-per-scenario' => '1',
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
                'error_code' => 'invalid_sample_scope',
            ]);

        $exitCode = Artisan::call(
            'operations:issue-browser-workload-permit',
            [
                '--actor-email' => [$actor->email],
                '--samples-per-scenario' => '1',
                '--ttl' => '60',
                '--acknowledge-load' => true,
                '--allow-undersampled-rehearsal' => true,
                '--json' => true,
            ],
        );
        $output = trim(Artisan::output());
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $absolutePath = base_path(str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $payload['permit_file'],
        ));
        $privatePermit = json_decode(
            file_get_contents($absolutePath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($exitCode)->toBe(0)
            ->and($payload['status'])->toBe('issued')
            ->and($payload['actors'])->toBe(1)
            ->and($payload['scenarios'])->toBe(3)
            ->and($payload['samples_per_scenario'])->toBe(1)
            ->and($payload['authorizations'])->toBe(3)
            ->and($payload['evidence_eligible'])->toBeFalse()
            ->and($privatePermit['permit'])->toHaveLength(43)
            ->and($privatePermit['origin'])->toBe('https://staging.example.test')
            ->and($privatePermit['contract_hash'])->toBe(
                '1f0e123d46eaa88af24da95058557f71ff512a26060ac3dff7a969d146f2deb7',
            )
            ->and($privatePermit['budgets']['version'])->toBe('browser-workload-budget:v1')
            ->and($privatePermit['profile']['version'])->toBe('browser-desktop-profile:v1')
            ->and($output)->not->toContain($privatePermit['permit']);
    } finally {
        if (is_string($absolutePath) && is_file($absolutePath)) {
            unlink($absolutePath);
        }

        $application->detectEnvironment(fn (): string => $originalEnvironment);
    }
})->group('capacity');

test('the browser permit command has no production override', function () {
    $application = app();
    $originalEnvironment = $application->environment();
    $application->detectEnvironment(fn (): string => 'production');

    try {
        $exitCode = Artisan::call(
            'operations:issue-browser-workload-permit',
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
        $application->detectEnvironment(fn (): string => $originalEnvironment);
    }

    expect($exitCode)->toBe(1)
        ->and($payload)->toBe([
            'status' => 'failed',
            'error_code' => 'production_forbidden',
        ]);
})->group('capacity');
