<?php

namespace App\Enums\Subscriptions;

enum FeatureCode: string
{
    case MonthlyAnalyses = 'analyses.monthly';
    case SavedSearches = 'saved_searches.total';
    case TeamMembers = 'team_members.total';
    case EmailNotifications = 'notifications.email';
    case TelegramNotifications = 'notifications.telegram';
    case BasicPriceHistory = 'price_history.basic';
    case FullRiskReport = 'reports.full_risk';
    case PriorityAnalysis = 'analysis.priority';
    case ProfitTracking = 'profit_tracking';
    case OrganizationReporting = 'organization.reporting';
    case MonthlyBrokerRequests = 'broker_requests.monthly';
    case MonthlyExports = 'exports.monthly';
    case PrioritySupport = 'support.priority';

    public function isMetered(): bool
    {
        return str_ends_with($this->value, '.monthly');
    }
}
