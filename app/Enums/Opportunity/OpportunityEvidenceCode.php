<?php

namespace App\Enums\Opportunity;

enum OpportunityEvidenceCode: string
{
    case ShippingMethod = 'shipping_method';
    case ShippingDistanceKm = 'shipping_distance_km';
    case PickupAvailable = 'pickup_available';
    case TrackingAvailable = 'tracking_available';
    case InsuranceAvailable = 'insurance_available';
    case PackagingConfirmed = 'packaging_confirmed';
    case CrossBorderHandlingConfirmed = 'cross_border_handling_confirmed';
    case TransportCostKnown = 'transport_cost_known';
    case RegionalCompatibilityConfirmed = 'regional_compatibility_confirmed';
    case SoldComparablesCount = 'sold_comparables_count';
    case MedianDaysToSale = 'median_days_to_sale';
    case ObservationWindowDays = 'observation_window_days';
    case DemandEvidenceObservedAt = 'demand_evidence_observed_at';
    case DemandEvidenceSource = 'demand_evidence_source';
    case ComparableCount = 'comparable_count';
    case MedianComparableAgeDays = 'median_comparable_age_days';

    public function component(): OpportunityComponent
    {
        return match ($this) {
            self::ShippingMethod,
            self::ShippingDistanceKm,
            self::PickupAvailable,
            self::TrackingAvailable,
            self::InsuranceAvailable,
            self::PackagingConfirmed,
            self::CrossBorderHandlingConfirmed,
            self::TransportCostKnown,
            self::RegionalCompatibilityConfirmed => OpportunityComponent::Logistics,
            default => OpportunityComponent::Demand,
        };
    }

    public function position(): int
    {
        return match ($this) {
            self::ShippingMethod => 1,
            self::ShippingDistanceKm => 2,
            self::PickupAvailable => 3,
            self::TrackingAvailable => 4,
            self::InsuranceAvailable => 5,
            self::PackagingConfirmed => 6,
            self::CrossBorderHandlingConfirmed => 7,
            self::TransportCostKnown => 8,
            self::RegionalCompatibilityConfirmed => 9,
            self::SoldComparablesCount => 1,
            self::MedianDaysToSale => 2,
            self::ObservationWindowDays => 3,
            self::DemandEvidenceObservedAt => 4,
            self::DemandEvidenceSource => 5,
            self::ComparableCount => 6,
            self::MedianComparableAgeDays => 7,
        };
    }
}
