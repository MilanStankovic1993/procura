<?php

namespace App\Actions\Markets;

use App\Enums\Markets\MeasurementSystem;
use App\Enums\Organizations\OrganizationPermission;
use App\Enums\Organizations\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMarketPreference;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ManageOrganizationMarketPreferences
{
    /**
     * @param  list<string>  $countryCodes
     */
    public function update(
        Organization $organization,
        User $actor,
        ?string $homeCountryCode,
        ?string $reportingCurrencyCode,
        string $locale,
        string $timezone,
        MeasurementSystem $measurementSystem,
        bool $includeCrossBorder,
        array $countryCodes,
    ): OrganizationMarketPreference {
        return DB::transaction(function () use (
            $organization, $actor, $homeCountryCode, $reportingCurrencyCode,
            $locale, $timezone, $measurementSystem, $includeCrossBorder, $countryCodes,
        ): OrganizationMarketPreference {
            $lockedOrganization = Organization::query()
                ->lockForUpdate()
                ->findOrFail($organization->getKey());
            $membership = OrganizationMembership::query()
                ->where('organization_id', $lockedOrganization->getKey())
                ->where('user_id', $actor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($membership->role !== OrganizationRole::Owner
                && ! $membership->role->allows(OrganizationPermission::UpdateOrganization)) {
                throw new AuthorizationException;
            }

            $preference = OrganizationMarketPreference::query()->updateOrCreate(
                ['organization_id' => $lockedOrganization->getKey()],
                [
                    'home_country_code' => $homeCountryCode,
                    'reporting_currency_code' => $reportingCurrencyCode,
                    'locale' => $locale,
                    'timezone' => $timezone,
                    'measurement_system' => $measurementSystem,
                    'include_cross_border' => $includeCrossBorder,
                ],
            );

            DB::table('organization_market_countries')
                ->where('organization_id', $lockedOrganization->getKey())
                ->delete();

            $now = now();
            DB::table('organization_market_countries')->insert(
                array_map(
                    static fn (string $countryCode, int $index): array => [
                        'organization_id' => $lockedOrganization->getKey(),
                        'country_code' => $countryCode,
                        'sort_order' => $index,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $countryCodes,
                    array_keys($countryCodes),
                ),
            );

            return $preference;
        }, attempts: 3);
    }
}
