<?php

namespace App\Console\Commands;

use App\Actions\BrokerRequests\PresentBrokerRequestOffer;
use App\Models\BrokerRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PresentBrokerRequestOfferCommand extends Command
{
    protected $signature = 'broker-offers:present
        {request : Searching broker-request ULID}
        {--actor-email= : Verified super-administrator email}
        {--expected-request-event= : Exact current broker-request event ULID}
        {--idempotency= : UUID retained across an exact retry}
        {--supplier-name= : Tenant-safe supplier display name}
        {--supplier-reference= : Private operational supplier reference}
        {--description= : Exact item and included-scope description}
        {--condition= : new, used, or refurbished}
        {--quantity= : Offered quantity}
        {--unit-price-minor= : Unit price in integer minor currency units}
        {--shipping-minor=0 : Shipping in integer minor currency units}
        {--tax-duty-minor=0 : Tax and duty in integer minor currency units}
        {--other-cost-minor=0 : Other disclosed cost in minor units}
        {--currency= : Active ISO-4217 currency code}
        {--origin-country= : Optional active ISO-3166 alpha-2 code}
        {--estimated-delivery-date= : Optional YYYY-MM-DD delivery date}
        {--valid-until= : Future ISO-8601 offer validity time}
        {--warranty-months= : Optional whole warranty months}
        {--return-policy= : Optional tenant-safe return-policy summary}
        {--evidence= : External case or supplier-quote evidence reference}';

    protected $description = 'Present one immutable, evidence-bound broker offer';

    public function handle(PresentBrokerRequestOffer $action): int
    {
        $request = BrokerRequest::query()->find(
            trim((string) $this->argument('request')),
        );
        $actor = User::query()
            ->where(
                'email',
                Str::lower(trim((string) $this->option('actor-email'))),
            )
            ->first();

        if ($request === null || $actor === null) {
            $this->components->error(
                'The broker request or operator account was not found.',
            );

            return self::FAILURE;
        }

        $quantity = $this->integerOption('quantity');
        $unitPrice = $this->integerOption('unit-price-minor');
        $shipping = $this->integerOption('shipping-minor');
        $taxDuty = $this->integerOption('tax-duty-minor');
        $otherCost = $this->integerOption('other-cost-minor');
        $warranty = $this->nullableIntegerOption('warranty-months');
        $warrantyWasProvided = (
            $this->option('warranty-months') !== null
            && trim((string) $this->option('warranty-months')) !== ''
        );

        if (
            $quantity === null
            || $unitPrice === null
            || $shipping === null
            || $taxDuty === null
            || $otherCost === null
            || ($warrantyWasProvided && $warranty === null)
        ) {
            $this->components->error(
                'Quantity and all monetary values must be whole numbers.',
            );

            return self::FAILURE;
        }

        try {
            $result = $action->execute(
                request: $request,
                actor: $actor,
                expectedRequestEventId: trim(
                    (string) $this->option('expected-request-event'),
                ),
                idempotencyKey: trim(
                    (string) $this->option('idempotency'),
                ),
                evidenceReference: trim(
                    (string) $this->option('evidence'),
                ),
                input: [
                    'supplier_display_name' => $this->option('supplier-name'),
                    'supplier_reference' => (
                        $this->option('supplier-reference')
                    ),
                    'item_description' => $this->option('description'),
                    'condition' => $this->option('condition'),
                    'quantity' => $quantity,
                    'unit_price_minor' => $unitPrice,
                    'shipping_cost_minor' => $shipping,
                    'tax_duty_cost_minor' => $taxDuty,
                    'other_cost_minor' => $otherCost,
                    'currency_code' => $this->option('currency'),
                    'origin_country_code' => $this->option('origin-country'),
                    'estimated_delivery_date' => (
                        $this->option('estimated-delivery-date')
                    ),
                    'valid_until' => $this->option('valid-until'),
                    'warranty_months' => $warranty,
                    'return_policy_summary' => (
                        $this->option('return-policy')
                    ),
                ],
            );
        } catch (
            AuthorizationException
            |InvalidArgumentException
            |ValidationException $exception
        ) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Broker offer %s is presented on request %s (%s event).',
            $result->offer->getKey(),
            $request->getKey(),
            $result->created ? 'new' : 'replayed',
        ));

        return self::SUCCESS;
    }

    private function integerOption(string $name): ?int
    {
        $value = filter_var(
            $this->option($name),
            FILTER_VALIDATE_INT,
        );

        return $value === false ? null : $value;
    }

    private function nullableIntegerOption(string $name): ?int
    {
        $raw = $this->option($name);

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return $this->integerOption($name);
    }
}
