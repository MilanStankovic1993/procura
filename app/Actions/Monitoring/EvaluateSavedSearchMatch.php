<?php

namespace App\Actions\Monitoring;

use App\Enums\Catalog\ProductMatchStatus;
use App\Enums\DealScoring\DealScoreStatus;
use App\Enums\Monitoring\AlertType;
use App\Enums\Monitoring\NotificationChannel;
use App\Enums\Monitoring\NotificationEventType;
use App\Enums\Monitoring\SavedSearchMatchStatus;
use App\Enums\Profit\ProfitEstimateStatus;
use App\Jobs\Monitoring\SendAlertEmail;
use App\Jobs\Monitoring\SendAlertTelegram;
use App\Models\Alert;
use App\Models\Analysis;
use App\Models\Country;
use App\Models\Currency;
use App\Models\ListingSnapshot;
use App\Models\NotificationLog;
use App\Models\OrganizationMembership;
use App\Models\SavedSearch;
use App\Models\SavedSearchMatch;
use App\Models\SavedSearchVersion;
use App\Monitoring\DeterministicSavedSearchMatcher;
use App\Monitoring\SavedSearchNotificationEntitlements;
use App\Monitoring\Telegram\TelegramConfiguration;
use Illuminate\Support\Facades\DB;

class EvaluateSavedSearchMatch
{
    public function __construct(
        private readonly DeterministicSavedSearchMatcher $matcher,
        private readonly SavedSearchNotificationEntitlements $notificationEntitlements,
        private readonly TelegramConfiguration $telegramConfiguration,
    ) {}

    public function evaluate(
        SavedSearchVersion $version,
        ListingSnapshot $snapshot,
    ): ?SavedSearchMatch {
        $version->loadMissing('savedSearch');
        $savedSearch = $version->savedSearch;

        if (
            $savedSearch->archived_at !== null
            || ! $savedSearch->active
            || $savedSearch->current_version_id !== $version->getKey()
        ) {
            return null;
        }

        $snapshot->loadMissing('listing');

        if (
            $snapshot->listing->organization_id
            !== $savedSearch->organization_id
        ) {
            return null;
        }

        $analysis = $this->analysis($version, $snapshot);
        $productMatch = $analysis?->currentProductMatch;
        $profitEstimate = $analysis?->currentProfitEstimate;
        $riskAssessment = $analysis?->currentRiskAssessment;
        $dealScore = $analysis?->currentDealScore;
        $sourceContinent = Country::query()
            ->whereKey($snapshot->source_country_code)
            ->value('continent_code');
        $listingEvidence = [
            'listing_id' => $snapshot->listing_id,
            'listing_snapshot_id' => $snapshot->getKey(),
            'title' => $snapshot->title,
            'description' => $snapshot->description,
            'asking_price_minor' => $snapshot->asking_price_minor,
            'currency_code' => $snapshot->currency_code,
            'location' => $snapshot->location,
            'source_country_code' => $snapshot->source_country_code,
            'target_country_code' => $snapshot->target_country_code,
            'source_continent_code' => $sourceContinent,
            'captured_at' => $snapshot->captured_at?->toIso8601String(),
            'content_hash' => $snapshot->content_hash,
        ];
        $analysisEvidence = [
            'analysis_id' => $analysis?->getKey(),
            'product_match_id' => $productMatch?->getKey(),
            'product_category_id' => $productMatch?->status
                === ProductMatchStatus::Matched
                ? $productMatch->productModel?->product_category_id
                : null,
            'brand_id' => $productMatch?->status
                === ProductMatchStatus::Matched
                ? $productMatch->productModel?->brand_id
                : null,
            'product_model_id' => $productMatch?->status
                === ProductMatchStatus::Matched
                ? $productMatch->product_model_id
                : null,
            'profit_estimate_id' => $profitEstimate?->getKey(),
            'expected_net_profit_minor' => in_array(
                $profitEstimate?->status,
                [
                    ProfitEstimateStatus::Estimated,
                    ProfitEstimateStatus::LowConfidence,
                ],
                true,
            )
                ? $profitEstimate?->expected_net_profit_minor
                : null,
            'profit_margin_basis_points' => in_array(
                $profitEstimate?->status,
                [
                    ProfitEstimateStatus::Estimated,
                    ProfitEstimateStatus::LowConfidence,
                ],
                true,
            )
                ? $profitEstimate?->profit_margin_basis_points
                : null,
            'profit_currency_code' => in_array(
                $profitEstimate?->status,
                [
                    ProfitEstimateStatus::Estimated,
                    ProfitEstimateStatus::LowConfidence,
                ],
                true,
            )
                ? $profitEstimate?->currency_code
                : null,
            'risk_assessment_id' => $riskAssessment?->getKey(),
            'risk_score' => $riskAssessment?->score,
            'deal_score_id' => $dealScore?->getKey(),
            'deal_score_basis_points' => $dealScore?->status
                === DealScoreStatus::Assessed
                ? $dealScore->score_basis_points
                : null,
        ];
        $decision = $this->matcher->evaluate(
            $version->criteria_snapshot,
            $listingEvidence,
            $analysisEvidence,
        );
        $matcherVersion = (string) config('monitoring.matcher_version');
        $matchKey = hash(
            'sha256',
            implode('|', [
                $version->getKey(),
                $snapshot->getKey(),
                $snapshot->content_hash,
                $analysis?->getKey() ?? 'none',
                $productMatch?->getKey() ?? 'none',
                $profitEstimate?->getKey() ?? 'none',
                $riskAssessment?->getKey() ?? 'none',
                $dealScore?->getKey() ?? 'none',
                $matcherVersion,
            ]),
        );

        return DB::transaction(function () use (
            $version,
            $savedSearch,
            $snapshot,
            $analysis,
            $productMatch,
            $profitEstimate,
            $riskAssessment,
            $dealScore,
            $decision,
            $matcherVersion,
            $matchKey,
        ): SavedSearchMatch {
            $existing = SavedSearchMatch::query()
                ->where('match_key', $matchKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $match = SavedSearchMatch::query()->create([
                'organization_id' => $savedSearch->organization_id,
                'saved_search_id' => $savedSearch->getKey(),
                'saved_search_version_id' => $version->getKey(),
                'listing_id' => $snapshot->listing_id,
                'listing_snapshot_id' => $snapshot->getKey(),
                'analysis_id' => $analysis?->getKey(),
                'product_match_id' => $productMatch?->getKey(),
                'profit_estimate_id' => $profitEstimate?->getKey(),
                'risk_assessment_id' => $riskAssessment?->getKey(),
                'deal_score_id' => $dealScore?->getKey(),
                'status' => $decision->status,
                'matcher_version' => $matcherVersion,
                'match_key' => $matchKey,
                'reason_codes' => $decision->reasonCodes,
                'unknown_criteria' => $decision->unknownCriteria,
                'evidence_snapshot' => $decision->evidence,
                'evaluated_at' => now(),
                'created_at' => now(),
            ]);

            if ($decision->status === SavedSearchMatchStatus::Matched) {
                $this->createAlert($savedSearch, $version, $match, $snapshot);
            }

            return $match;
        }, attempts: 3);
    }

    private function analysis(
        SavedSearchVersion $version,
        ListingSnapshot $snapshot,
    ): ?Analysis {
        $needsAnalysis = $version->product_category_id !== null
            || $version->brand_id !== null
            || $version->product_model_id !== null
            || $version->minimum_profit_minor !== null
            || $version->minimum_margin_basis_points !== null
            || $version->minimum_deal_score_basis_points !== null
            || $version->maximum_risk_score !== null;

        if (! $needsAnalysis) {
            return null;
        }

        return Analysis::query()
            ->forOrganization($version->organization_id)
            ->where('listing_id', $snapshot->listing_id)
            ->where('listing_snapshot_id', $snapshot->getKey())
            ->with([
                'currentProductMatch.productModel',
                'currentProfitEstimate',
                'currentRiskAssessment',
                'currentDealScore',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function createAlert(
        SavedSearch $savedSearch,
        SavedSearchVersion $version,
        SavedSearchMatch $match,
        ListingSnapshot $snapshot,
    ): void {
        $recipientStillBelongs = OrganizationMembership::query()
            ->where('organization_id', $savedSearch->organization_id)
            ->where('user_id', $savedSearch->owner_user_id)
            ->exists();

        if (
            ! $recipientStillBelongs
            || ! in_array(
                NotificationChannel::InApp->value,
                $version->notification_channels,
                true,
            )
        ) {
            return;
        }

        $alertKey = hash(
            'sha256',
            implode('|', [
                $savedSearch->organization_id,
                $savedSearch->owner_user_id,
                $snapshot->listing_id,
                $savedSearch->getKey(),
                AlertType::SavedSearchMatch->value,
            ]),
        );
        $analysisEvidence = $match->evidence_snapshot['analysis'] ?? [];
        $profitCurrencyCode = $analysisEvidence['profit_currency_code'] ?? null;
        $minorUnits = Currency::query()
            ->whereIn(
                'code',
                array_values(array_filter([
                    $snapshot->currency_code,
                    $profitCurrencyCode,
                ])),
            )
            ->pluck('minor_unit', 'code');
        $payload = [
            'title_key' => 'monitoring.alert.savedSearchMatch.title',
            'body_key' => 'monitoring.alert.savedSearchMatch.body',
            'saved_search_title' => $savedSearch->title,
            'listing_title' => $snapshot->title,
            'asking_price_minor' => $snapshot->asking_price_minor,
            'currency_code' => $snapshot->currency_code,
            'currency_minor_unit' => $snapshot->currency_code === null
                ? null
                : $minorUnits->get($snapshot->currency_code),
            'source_country_code' => $snapshot->source_country_code,
            'listing_id' => $snapshot->listing_id,
            'saved_search_id' => $savedSearch->getKey(),
            'expected_net_profit_minor' => (
                $analysisEvidence['expected_net_profit_minor'] ?? null
            ),
            'profit_currency_code' => $profitCurrencyCode,
            'profit_currency_minor_unit' => $profitCurrencyCode === null
                ? null
                : $minorUnits->get($profitCurrencyCode),
            'deal_score_basis_points' => (
                $analysisEvidence['deal_score_basis_points'] ?? null
            ),
            'risk_score' => $analysisEvidence['risk_score'] ?? null,
        ];
        $alert = Alert::query()->firstOrCreate(
            ['alert_key' => $alertKey],
            [
                'organization_id' => $savedSearch->organization_id,
                'recipient_user_id' => $savedSearch->owner_user_id,
                'saved_search_id' => $savedSearch->getKey(),
                'saved_search_version_id' => $version->getKey(),
                'saved_search_match_id' => $match->getKey(),
                'listing_id' => $snapshot->listing_id,
                'listing_snapshot_id' => $snapshot->getKey(),
                'alert_type' => AlertType::SavedSearchMatch,
                'payload' => $payload,
                'triggered_at' => now(),
                'created_at' => now(),
            ],
        );

        if (! $alert->wasRecentlyCreated) {
            return;
        }

        NotificationLog::query()->create([
            'organization_id' => $savedSearch->organization_id,
            'alert_id' => $alert->getKey(),
            'recipient_user_id' => $savedSearch->owner_user_id,
            'channel' => NotificationChannel::InApp,
            'event_type' => NotificationEventType::Delivered,
            'sequence' => 1,
            'payload' => $payload,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $recipient = $savedSearch->owner()->firstOrFail();
        $organization = $savedSearch->organization()->firstOrFail();

        if (in_array(
            NotificationChannel::Email->value,
            $version->notification_channels,
            true,
        )) {
            $emailEnabled = $this->notificationEntitlements->emailEnabled(
                $organization,
            );
            $eventType = (
                $recipient->hasVerifiedEmail() && $emailEnabled
            )
                ? NotificationEventType::Queued
                : NotificationEventType::Suppressed;
            $reasonCode = match (true) {
                ! $recipient->hasVerifiedEmail() => (
                    'recipient_email_unverified'
                ),
                ! $emailEnabled => 'email_entitlement_disabled',
                default => null,
            };
            NotificationLog::query()->create([
                'organization_id' => $savedSearch->organization_id,
                'alert_id' => $alert->getKey(),
                'recipient_user_id' => $savedSearch->owner_user_id,
                'channel' => NotificationChannel::Email,
                'event_type' => $eventType,
                'sequence' => 1,
                'payload' => [
                    ...$payload,
                    'delivery' => [
                        'provider' => (string) config(
                            'monitoring.email_delivery.provider',
                            'laravel-mail',
                        ),
                        'reason_code' => $reasonCode,
                    ],
                ],
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            if ($eventType === NotificationEventType::Queued) {
                DB::afterCommit(
                    static fn () => SendAlertEmail::dispatch(
                        $alert->getKey(),
                    ),
                );
            }
        }

        if (in_array(
            NotificationChannel::Telegram->value,
            $version->notification_channels,
            true,
        )) {
            $telegramEnabled = (
                $this->notificationEntitlements->telegramEnabled(
                    $organization,
                )
            );
            $connection = (
                $this->notificationEntitlements->telegramConnectionFor(
                    $recipient,
                )
            );
            $providerConfigured = (
                $this->telegramConfiguration->isConfigured()
            );
            $eventType = (
                $telegramEnabled
                && $connection !== null
                && $providerConfigured
            )
                ? NotificationEventType::Queued
                : NotificationEventType::Suppressed;
            $reasonCode = match (true) {
                ! $telegramEnabled => 'telegram_entitlement_disabled',
                $connection === null => 'telegram_connection_missing',
                ! $providerConfigured => 'telegram_provider_not_configured',
                default => null,
            };
            NotificationLog::query()->create([
                'organization_id' => $savedSearch->organization_id,
                'alert_id' => $alert->getKey(),
                'recipient_user_id' => $savedSearch->owner_user_id,
                'channel' => NotificationChannel::Telegram,
                'event_type' => $eventType,
                'sequence' => 1,
                'payload' => [
                    ...$payload,
                    'delivery' => [
                        'provider' => $this->telegramConfiguration->provider(),
                        'connection_id' => $connection?->getKey(),
                        'reason_code' => $reasonCode,
                    ],
                ],
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            if ($eventType === NotificationEventType::Queued) {
                DB::afterCommit(
                    static fn () => SendAlertTelegram::dispatch(
                        $alert->getKey(),
                    ),
                );
            }
        }
    }
}
