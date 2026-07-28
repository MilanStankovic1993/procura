<?php

use App\Actions\Markets\SyncMarketReferenceData;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(SyncMarketReferenceData::class)->sync();
});

test('owners can persist ordered multi-country market defaults', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    $owner->update(['current_organization_id' => $organization->getKey()]);

    $payload = [
        'home_country_code' => 'AT',
        'reporting_currency_code' => 'EUR',
        'locale' => 'de-AT',
        'timezone' => 'Europe/Vienna',
        'measurement_system' => 'metric',
        'include_cross_border' => true,
        'country_codes' => ['AT', 'DE', 'HU'],
    ];

    $this->actingAs($owner)
        ->putJson(route('api.v1.organization.market-preferences.update'), $payload)
        ->assertOk()
        ->assertJsonPath('data.country_codes', ['AT', 'DE', 'HU'])
        ->assertJsonPath('data.include_cross_border', true);

    expect(DB::table('organization_market_countries')
        ->where('organization_id', $organization->getKey())
        ->orderBy('sort_order')
        ->pluck('country_code')
        ->all())->toBe(['AT', 'DE', 'HU']);
});

test('market preferences reject forged codes duplicates and unauthorized members', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMembership::factory()->owner()->create([
        'organization_id' => $organization,
        'user_id' => $owner,
    ]);
    OrganizationMembership::factory()->create([
        'organization_id' => $organization,
        'user_id' => $viewer,
        'role' => OrganizationRole::Viewer,
    ]);
    $viewer->update(['current_organization_id' => $organization->getKey()]);

    $validPayload = [
        'home_country_code' => 'AT',
        'reporting_currency_code' => 'EUR',
        'locale' => 'en',
        'timezone' => 'UTC',
        'measurement_system' => 'metric',
        'include_cross_border' => false,
        'country_codes' => ['AT'],
    ];

    $this->actingAs($viewer)
        ->putJson(route('api.v1.organization.market-preferences.update'), $validPayload)
        ->assertForbidden();

    $owner->update(['current_organization_id' => $organization->getKey()]);

    $this->actingAs($owner)
        ->putJson(route('api.v1.organization.market-preferences.update'), [
            ...$validPayload,
            'home_country_code' => 'ZZ',
            'country_codes' => ['AT', 'AT', 'ZZ'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['home_country_code', 'country_codes.1', 'country_codes.2']);
});

test('market preferences are isolated to the active organization context', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();

    foreach ([$first, $second] as $organization) {
        OrganizationMembership::factory()->owner()->create([
            'organization_id' => $organization,
            'user_id' => $user,
        ]);
    }

    $user->update(['current_organization_id' => $first->getKey()]);

    $this->actingAs($user)
        ->putJson(route('api.v1.organization.market-preferences.update'), [
            'home_country_code' => 'AT',
            'reporting_currency_code' => 'EUR',
            'locale' => 'en',
            'timezone' => 'UTC',
            'measurement_system' => 'metric',
            'include_cross_border' => false,
            'country_codes' => ['AT'],
        ])
        ->assertOk();

    expect(DB::table('organization_market_preferences')->count())->toBe(1)
        ->and(DB::table('organization_market_preferences')
            ->value('organization_id'))->toBe($first->getKey())
        ->and(DB::table('organization_market_preferences')
            ->where('organization_id', $second->getKey())
            ->exists())->toBeFalse();
});
