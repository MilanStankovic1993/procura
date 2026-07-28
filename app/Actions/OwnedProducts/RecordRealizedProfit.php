<?php

namespace App\Actions\OwnedProducts;

use App\Enums\Outcomes\ActualSaleOutcomeType;
use App\Models\ActualCostSnapshot;
use App\Models\ActualPurchase;
use App\Models\ActualSale;
use App\Models\OwnedProduct;
use App\Models\RealizedProfit;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use JsonException;
use OverflowException;

final class RecordRealizedProfit
{
    /**
     * @throws JsonException
     */
    public function recordIfComplete(OwnedProduct $product): ?RealizedProfit
    {
        $purchase = ActualPurchase::query()
            ->where('owned_product_id', $product->getKey())
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first();
        $costs = ActualCostSnapshot::query()
            ->where('owned_product_id', $product->getKey())
            ->with('items')
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first();
        $sale = ActualSale::query()
            ->where('owned_product_id', $product->getKey())
            ->where('outcome_type', ActualSaleOutcomeType::Sold->value)
            ->orderByDesc('created_at')
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (
            $purchase === null
            || $costs === null
            || $sale === null
            || $costs->unknown_count > 0
            || $purchase->reporting_currency_code
                !== $costs->reporting_currency_code
            || $sale->reporting_currency_code
                !== $costs->reporting_currency_code
        ) {
            return null;
        }

        $calculationVersion = (string) config(
            'outcome_tracking.calculation_version',
        );
        $inputSnapshot = [
            'owned_product_id' => $product->getKey(),
            'actual_purchase' => [
                'id' => $purchase->getKey(),
                'input_hash' => $purchase->input_hash,
                'reporting_amount_minor' => (
                    $purchase->reporting_amount_minor
                ),
                'reporting_currency_code' => (
                    $purchase->reporting_currency_code
                ),
            ],
            'actual_cost_snapshot' => [
                'id' => $costs->getKey(),
                'input_hash' => $costs->input_hash,
                'reporting_amount_minor' => (
                    $costs->known_reporting_total_minor
                ),
                'reporting_currency_code' => (
                    $costs->reporting_currency_code
                ),
                'item_ids' => $costs->items->pluck('id')->all(),
            ],
            'actual_sale' => [
                'id' => $sale->getKey(),
                'input_hash' => $sale->input_hash,
                'sale_portfolio_entry_id' => (
                    $sale->sale_portfolio_entry_id
                ),
                'sale_portfolio_event_id' => (
                    $sale->sale_portfolio_event_id
                ),
                'reporting_amount_minor' => $sale->reporting_amount_minor,
                'reporting_currency_code' => $sale->reporting_currency_code,
                'sale_duration_seconds' => $sale->sale_duration_seconds,
            ],
            'calculation_version' => $calculationVersion,
        ];
        $inputHash = hash(
            'sha256',
            json_encode(
                $inputSnapshot,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
        );
        $calculationKey = hash('sha256', implode('|', [
            $product->getKey(),
            $purchase->getKey(),
            $costs->getKey(),
            $sale->getKey(),
            $calculationVersion,
            $inputHash,
        ]));
        $existing = RealizedProfit::query()
            ->where('calculation_key', $calculationKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $totalInvested = $this->sumUnsigned(
            $purchase->reporting_amount_minor,
            $costs->known_reporting_total_minor,
        );
        $netProfit = $sale->reporting_amount_minor - $totalInvested;
        $margin = $this->boundedRatio(
            $netProfit,
            $sale->reporting_amount_minor,
        );
        $return = $this->boundedRatio($netProfit, $totalInvested);
        $reasonCodes = ['complete_realized_evidence_chain'];

        if ($netProfit < 0) {
            $reasonCodes[] = 'negative_realized_profit';
        } else {
            $reasonCodes[] = 'non_negative_realized_profit';
        }

        if ($margin === null || $return === null) {
            $reasonCodes[] = 'realized_profit_ratio_unavailable';
        }

        return DB::transaction(function () use (
            $product,
            $purchase,
            $costs,
            $sale,
            $calculationVersion,
            $calculationKey,
            $inputHash,
            $totalInvested,
            $netProfit,
            $margin,
            $return,
            $reasonCodes,
            $inputSnapshot,
        ): RealizedProfit {
            $runNumber = ((int) RealizedProfit::query()
                ->where('owned_product_id', $product->getKey())
                ->max('run_number')) + 1;

            return RealizedProfit::query()->create([
                'organization_id' => $product->organization_id,
                'owned_product_id' => $product->getKey(),
                'actual_purchase_id' => $purchase->getKey(),
                'actual_cost_snapshot_id' => $costs->getKey(),
                'actual_sale_id' => $sale->getKey(),
                'run_number' => $runNumber,
                'calculation_version' => $calculationVersion,
                'calculation_key' => $calculationKey,
                'input_hash' => $inputHash,
                'currency_code' => $costs->reporting_currency_code,
                'purchase_price_minor' => (
                    $purchase->reporting_amount_minor
                ),
                'sale_price_minor' => $sale->reporting_amount_minor,
                'actual_costs_minor' => (
                    $costs->known_reporting_total_minor
                ),
                'total_invested_minor' => $totalInvested,
                'net_profit_minor' => $netProfit,
                'profit_margin_basis_points' => $margin,
                'return_on_invested_capital_basis_points' => $return,
                'sale_duration_seconds' => $sale->sale_duration_seconds,
                'reason_codes' => $reasonCodes,
                'input_snapshot' => $inputSnapshot,
                'calculated_at' => CarbonImmutable::now()->utc(),
            ]);
        });
    }

    private function sumUnsigned(int ...$amounts): int
    {
        $total = array_sum($amounts);

        if ($total > PHP_INT_MAX || $total < 0) {
            throw new OverflowException(
                'The realized cost total exceeds the supported integer range.',
            );
        }

        return $total;
    }

    private function boundedRatio(int $numerator, int $denominator): ?int
    {
        if ($denominator <= 0) {
            return null;
        }

        $ratio = BigInteger::of($numerator)
            ->multipliedBy(10000)
            ->dividedBy($denominator, RoundingMode::HalfEven)
            ->toInt();

        return abs($ratio) <= (int) config(
            'outcome_tracking.maximum_ratio_basis_points',
        )
            ? $ratio
            : null;
    }
}
