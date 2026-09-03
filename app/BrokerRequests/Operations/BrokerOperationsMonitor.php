<?php

namespace App\BrokerRequests\Operations;

use App\BrokerRequests\Operations\Data\BrokerOperationsReport;
use App\Enums\BrokerRequests\BrokerCommissionStatus;
use App\Enums\BrokerRequests\BrokerPaymentCaseStatus;
use App\Enums\BrokerRequests\BrokerReportStatus;
use App\Enums\BrokerRequests\BrokerRequestOfferStatus;
use App\Enums\BrokerRequests\BrokerRequestStatus;
use App\Enums\BrokerRequests\BrokerTransactionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class BrokerOperationsMonitor
{
    private const SIGNAL_KEYS = [
        'requests_aging',
        'requests_past_needed_by',
        'offers_expired',
        'transactions_aging',
        'commissions_aging',
        'reports_overdue_purge',
        'payment_cases_aging',
    ];

    public function __construct(
        private readonly BrokerOperationsConfiguration $configuration,
    ) {}

    public function inspect(): BrokerOperationsReport
    {
        $checkedAt = CarbonImmutable::now('UTC');
        $thresholds = $this->configuration->thresholds();
        $requestStatuses = [
            BrokerRequestStatus::Submitted->value,
            BrokerRequestStatus::Reviewing->value,
            BrokerRequestStatus::Searching->value,
            BrokerRequestStatus::OffersAvailable->value,
        ];
        $transactionStatuses = collect(BrokerTransactionStatus::cases())
            ->reject(fn (BrokerTransactionStatus $status): bool => (
                $status->isTerminal()
            ))
            ->map(fn (BrokerTransactionStatus $status): string => $status->value)
            ->all();
        $paymentCaseStatuses = [
            BrokerPaymentCaseStatus::Open->value,
            BrokerPaymentCaseStatus::UnderReview->value,
        ];

        $queries = [
            $this->signalQuery('broker_requests as requests', 'requests_aging')
                ->join(
                    'broker_request_events as request_events',
                    'request_events.id',
                    '=',
                    'requests.current_event_id',
                )
                ->whereIn('requests.status', $requestStatuses)
                ->where(
                    'request_events.occurred_at',
                    '<=',
                    $checkedAt->subHours($thresholds['request_age_hours']),
                )
                ->where(function (Builder $query) use ($checkedAt): void {
                    $query
                        ->whereNull('requests.needed_by')
                        ->orWhere(
                            'requests.needed_by',
                            '>=',
                            $checkedAt->toDateString(),
                        );
                }),
            $this->signalQuery(
                'broker_requests as requests',
                'requests_past_needed_by',
            )
                ->whereIn('requests.status', $requestStatuses)
                ->whereNotNull('requests.needed_by')
                ->where(
                    'requests.needed_by',
                    '<',
                    $checkedAt->toDateString(),
                ),
            $this->signalQuery(
                'broker_request_offers as offers',
                'offers_expired',
            )
                ->where(
                    'offers.status',
                    BrokerRequestOfferStatus::Presented->value,
                )
                ->where(
                    'offers.valid_until',
                    '<=',
                    $checkedAt->subHours(
                        $thresholds['offer_expiry_grace_hours'],
                    ),
                ),
            $this->signalQuery(
                'broker_transactions as transactions',
                'transactions_aging',
            )
                ->join(
                    'broker_transaction_events as transaction_events',
                    'transaction_events.id',
                    '=',
                    'transactions.current_event_id',
                )
                ->whereIn('transactions.status', $transactionStatuses)
                ->where(
                    'transaction_events.occurred_at',
                    '<=',
                    $checkedAt->subHours(
                        $thresholds['transaction_age_hours'],
                    ),
                ),
            $this->signalQuery(
                'broker_commissions as commissions',
                'commissions_aging',
            )
                ->where(
                    'commissions.status',
                    BrokerCommissionStatus::Earned->value,
                )
                ->whereNotNull('commissions.earned_at')
                ->where(
                    'commissions.earned_at',
                    '<=',
                    $checkedAt->subHours(
                        $thresholds['commission_age_hours'],
                    ),
                ),
            $this->signalQuery(
                'broker_reports as reports',
                'reports_overdue_purge',
            )
                ->where(
                    'reports.status',
                    BrokerReportStatus::Available->value,
                )
                ->where(
                    'reports.artifact_expires_at',
                    '<=',
                    $checkedAt->subHours(
                        $thresholds['report_purge_grace_hours'],
                    ),
                ),
            $this->signalQuery(
                'broker_payment_cases as payment_cases',
                'payment_cases_aging',
            )
                ->join(
                    'broker_payment_case_events as payment_case_events',
                    'payment_case_events.id',
                    '=',
                    'payment_cases.current_event_id',
                )
                ->whereIn('payment_cases.status', $paymentCaseStatuses)
                ->where(
                    'payment_case_events.occurred_at',
                    '<=',
                    $checkedAt->subHours(
                        $thresholds['payment_case_age_hours'],
                    ),
                ),
        ];
        $union = array_shift($queries);

        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        $counts = array_fill_keys(self::SIGNAL_KEYS, 0);
        $rows = DB::query()
            ->fromSub($union, 'broker_operation_signals')
            ->get();

        foreach ($rows as $row) {
            $signal = (string) $row->operation_signal_key;

            if (array_key_exists($signal, $counts)) {
                $counts[$signal] = (int) $row->operation_signal_count;
            }
        }

        return new BrokerOperationsReport(
            checkedAt: $checkedAt,
            counts: $counts,
            thresholds: $thresholds,
        );
    }

    public function attentionCount(): int
    {
        return $this->inspect()->attentionCount();
    }

    private function signalQuery(string $table, string $signal): Builder
    {
        return DB::table($table)->selectRaw(
            '? AS operation_signal_key, COUNT(*) AS operation_signal_count',
            [$signal],
        );
    }
}
