<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Organizations\OrganizationPermission;
use App\Enums\OwnedProducts\OwnedProductStatus;
use App\Models\OwnedProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateOwnedProduct
{
    public function __construct(
        private readonly OwnedProductAuthorizer $authorizer,
        private readonly RecordOwnedProductSnapshot $snapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        OwnedProduct $ownedProduct,
        User $actor,
        array $attributes,
    ): OwnedProduct {
        return DB::transaction(function () use (
            $ownedProduct,
            $actor,
            $attributes,
        ): OwnedProduct {
            $locked = OwnedProduct::query()
                ->forOrganization($ownedProduct->organization_id)
                ->with('targetCountries')
                ->lockForUpdate()
                ->findOrFail($ownedProduct->getKey());

            $this->authorizer->authorize(
                $locked->organization,
                $actor,
                OrganizationPermission::ManageOwnedProducts,
                lockForUpdate: true,
            );

            if ($locked->status === OwnedProductStatus::Archived) {
                throw ValidationException::withMessages([
                    'status' => 'Archived owned products cannot be changed.',
                ]);
            }

            $targetStatus = isset($attributes['status'])
                ? OwnedProductStatus::from($attributes['status'])
                : $locked->status;

            if (! $locked->status->canTransitionTo($targetStatus)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'The owned-product lifecycle cannot transition from %s to %s.',
                        $locked->status->value,
                        $targetStatus->value,
                    ),
                ]);
            }

            $targetCountryCodes = $attributes['target_country_codes'] ?? null;
            unset($attributes['target_country_codes']);

            if (
                array_key_exists('purchase_history_known', $attributes)
                && $attributes['purchase_history_known'] === false
            ) {
                $attributes['purchase_history'] = null;
            }

            $existingCountries = $locked->targetCountries->pluck('code')->values()->all();
            $countriesChanged = $targetCountryCodes !== null
                && array_values($targetCountryCodes) !== $existingCountries;

            $locked->fill($attributes);
            $factsChanged = $locked->isDirty();

            if (! $factsChanged && ! $countriesChanged) {
                return $locked;
            }

            $locked->save();

            if ($targetCountryCodes !== null) {
                $pivot = [];

                foreach (array_values($targetCountryCodes) as $index => $countryCode) {
                    $pivot[$countryCode] = ['sort_order' => $index + 1];
                }

                // Replace inside the surrounding transaction so an order swap cannot
                // collide with the unique (owned_product_id, sort_order) boundary.
                $locked->targetCountries()->detach();
                $locked->targetCountries()->attach($pivot);
            }

            $locked->unsetRelation('targetCountries');
            $locked->load('targetCountries');
            $this->snapshots->record($locked, $actor, [
                'event' => 'intake_updated',
                'input' => [
                    ...$attributes,
                    ...($targetCountryCodes === null
                        ? []
                        : ['target_country_codes' => $targetCountryCodes]),
                ],
            ]);

            return $locked;
        }, attempts: 3);
    }
}
