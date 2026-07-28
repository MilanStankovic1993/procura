<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Organizations\OrganizationPermission;
use App\Models\Organization;
use App\Models\OwnedProduct;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateOwnedProduct
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly RecordOwnedProductSnapshot $snapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        Organization $organization,
        User $actor,
        array $attributes,
    ): OwnedProduct {
        return DB::transaction(function () use (
            $organization,
            $actor,
            $attributes,
        ): OwnedProduct {
            $this->authorizer->authorize(
                $organization,
                $actor,
                OrganizationPermission::ManageOwnedProducts,
                lockForUpdate: true,
            );

            $targetCountryCodes = $attributes['target_country_codes'];
            $ownedProduct = OwnedProduct::query()->forceCreate([
                ...Arr::except($attributes, ['target_country_codes']),
                'organization_id' => $organization->getKey(),
                'created_by_user_id' => $actor->getKey(),
                'raw_input' => $attributes,
            ]);
            $this->syncTargetCountries($ownedProduct, $targetCountryCodes);
            $ownedProduct->load('targetCountries');

            $this->snapshots->record($ownedProduct, $actor, [
                'event' => 'intake_created',
                'input' => $attributes,
            ]);

            return $ownedProduct;
        }, attempts: 3);
    }

    /**
     * @param  list<string>  $countryCodes
     */
    private function syncTargetCountries(
        OwnedProduct $ownedProduct,
        array $countryCodes,
    ): void {
        $pivot = [];

        foreach (array_values($countryCodes) as $index => $countryCode) {
            $pivot[$countryCode] = ['sort_order' => $index + 1];
        }

        $ownedProduct->targetCountries()->sync($pivot);
    }
}
