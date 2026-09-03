<?php

namespace App\Privacy;

use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationType;
use App\Enums\Privacy\PrivacyRequestType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AccountDeletionBlockerResolver
{
    /**
     * @return list<string>
     */
    public function snapshot(
        User $subject,
        PrivacyRequestType $type,
    ): array {
        if ($type !== PrivacyRequestType::AccountDeletion) {
            return [];
        }

        $codes = [
            'retention_review_required',
            ...$this->live($subject),
        ];
        sort($codes);

        return array_values(array_unique($codes));
    }

    /**
     * Blocking states which must actually be absent at execution time.
     *
     * @return list<string>
     */
    public function live(User $subject): array
    {
        $codes = [];

        if ($this->ownsBusiness($subject)) {
            $codes[] = 'business_ownership_transfer_required';
        }

        if ($this->ownsActiveSubscription($subject)) {
            $codes[] = 'active_subscription_resolution_required';
        }

        if ($this->hasActivePersonalBrokerRequest($subject)) {
            $codes[] = 'active_broker_request_resolution_required';
        }

        if ($this->hasAvailablePersonalBrokerReport($subject)) {
            $codes[] = 'broker_report_artifact_purge_required';
        }

        if ($this->hasOpenPersonalBrokerPaymentCase($subject)) {
            $codes[] = 'broker_payment_case_resolution_required';
        }

        if ($subject->is_super_admin) {
            $codes[] = 'super_admin_reassignment_required';
        }

        sort($codes);

        return $codes;
    }

    private function ownsBusiness(User $subject): bool
    {
        return DB::table('organization_user')
            ->join(
                'organizations',
                'organizations.id',
                '=',
                'organization_user.organization_id',
            )
            ->where('organization_user.user_id', $subject->getKey())
            ->where(
                'organization_user.role',
                OrganizationRole::Owner->value,
            )
            ->where(
                'organizations.type',
                OrganizationType::Business->value,
            )
            ->exists();
    }

    private function ownsActiveSubscription(User $subject): bool
    {
        return DB::table('organization_user')
            ->join(
                'subscriptions',
                'subscriptions.organization_id',
                '=',
                'organization_user.organization_id',
            )
            ->where('organization_user.user_id', $subject->getKey())
            ->where(
                'organization_user.role',
                OrganizationRole::Owner->value,
            )
            ->whereIn('subscriptions.stripe_status', [
                'active',
                'trialing',
                'past_due',
                'unpaid',
            ])
            ->where(static function ($query): void {
                $query
                    ->whereNull('subscriptions.ends_at')
                    ->orWhere('subscriptions.ends_at', '>', now());
            })
            ->exists();
    }

    private function hasActivePersonalBrokerRequest(User $subject): bool
    {
        return DB::table('broker_requests')
            ->join(
                'organizations',
                'organizations.id',
                '=',
                'broker_requests.organization_id',
            )
            ->where(
                'organizations.personal_user_id',
                $subject->getKey(),
            )
            ->whereNotIn('broker_requests.status', [
                'completed',
                'cancelled',
            ])
            ->exists();
    }

    private function hasAvailablePersonalBrokerReport(User $subject): bool
    {
        return DB::table('broker_reports')
            ->join(
                'organizations',
                'organizations.id',
                '=',
                'broker_reports.organization_id',
            )
            ->where(
                'organizations.personal_user_id',
                $subject->getKey(),
            )
            ->where('broker_reports.status', 'available')
            ->exists();
    }

    private function hasOpenPersonalBrokerPaymentCase(User $subject): bool
    {
        return DB::table('broker_payment_cases')
            ->join(
                'organizations',
                'organizations.id',
                '=',
                'broker_payment_cases.organization_id',
            )
            ->where(
                'organizations.personal_user_id',
                $subject->getKey(),
            )
            ->whereIn('broker_payment_cases.status', [
                'open',
                'under_review',
            ])
            ->exists();
    }
}
