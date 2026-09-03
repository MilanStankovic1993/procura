<?php

namespace App\Enums\DealScoring;

enum DealRecommendation: string
{
    case StrongOpportunity = 'strong_opportunity';
    case PotentialOpportunity = 'potential_opportunity';
    case NeedsVerification = 'needs_verification';
    case WeakOpportunity = 'weak_opportunity';
    case Avoid = 'avoid';
    case InsufficientData = 'insufficient_data';

    public static function fromScoreBasisPoints(int $basisPoints): self
    {
        $minimums = config(
            'deal_scoring.recommendation_minimum_basis_points',
        );

        return match (true) {
            $basisPoints >= $minimums[self::StrongOpportunity->value] => (
                self::StrongOpportunity
            ),
            $basisPoints >= $minimums[self::PotentialOpportunity->value] => (
                self::PotentialOpportunity
            ),
            $basisPoints >= $minimums[self::NeedsVerification->value] => (
                self::NeedsVerification
            ),
            $basisPoints >= $minimums[self::WeakOpportunity->value] => (
                self::WeakOpportunity
            ),
            default => self::Avoid,
        };
    }
}
