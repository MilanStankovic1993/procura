<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Actions\Markets\ManageOrganizationMarketPreferences;
use App\Enums\Markets\MeasurementSystem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Organizations\UpdateMarketPreferencesRequest;
use App\Models\OrganizationMarketPreference;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationMarketPreferencesController extends Controller
{
    public function show(Request $request, OrganizationContext $context): JsonResponse
    {
        return response()->json(['data' => $this->payload($context->organization()->getKey())]);
    }

    public function update(
        UpdateMarketPreferencesRequest $request,
        OrganizationContext $context,
        ManageOrganizationMarketPreferences $preferences,
    ): JsonResponse {
        $validated = $request->validated();

        $preferences->update(
            organization: $context->organization(),
            actor: $request->user(),
            homeCountryCode: $validated['home_country_code'] ?? null,
            reportingCurrencyCode: $validated['reporting_currency_code'] ?? null,
            locale: $validated['locale'],
            timezone: $validated['timezone'],
            measurementSystem: MeasurementSystem::from($validated['measurement_system']),
            includeCrossBorder: $validated['include_cross_border'],
            countryCodes: $validated['country_codes'],
        );

        return response()->json(['data' => $this->payload($context->organization()->getKey())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $organizationId): array
    {
        $preference = OrganizationMarketPreference::query()->find($organizationId);
        $countryCodes = DB::table('organization_market_countries')
            ->where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->pluck('country_code')
            ->all();

        return [
            'home_country_code' => $preference?->home_country_code,
            'reporting_currency_code' => $preference?->reporting_currency_code,
            'locale' => $preference?->locale ?? 'en',
            'timezone' => $preference?->timezone ?? 'UTC',
            'measurement_system' => $preference?->measurement_system->value ?? 'metric',
            'include_cross_border' => $preference?->include_cross_border ?? false,
            'country_codes' => $countryCodes,
        ];
    }
}
