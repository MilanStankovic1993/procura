<?php

namespace App\Enums\Privacy;

enum PrivacyRequestStatus: string
{
    case Requested = 'requested';
    case InReview = 'in_review';
    case ActionRequired = 'action_required';
    case Approved = 'approved';
    case Fulfilled = 'fulfilled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Fulfilled,
            self::Rejected,
            self::Cancelled,
        ], true);
    }

    public function canBeCancelledBySubject(): bool
    {
        return in_array($this, [
            self::Requested,
            self::InReview,
            self::ActionRequired,
        ], true);
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Requested => in_array($next, [
                self::InReview,
                self::ActionRequired,
                self::Rejected,
                self::Cancelled,
            ], true),
            self::InReview => in_array($next, [
                self::ActionRequired,
                self::Approved,
                self::Rejected,
                self::Cancelled,
            ], true),
            self::ActionRequired => in_array($next, [
                self::InReview,
                self::Approved,
                self::Rejected,
                self::Cancelled,
            ], true),
            self::Approved => in_array($next, [
                self::ActionRequired,
                self::Fulfilled,
            ], true),
            self::Fulfilled, self::Rejected, self::Cancelled => false,
        };
    }
}
