<?php

use App\Enums\BuyerDecisions\BuyerDecisionState;

test('buyer decision transition matrix is explicit and closed', function () {
    expect(array_map(
        static fn (BuyerDecisionState $state): string => $state->value,
        BuyerDecisionState::allowedFrom(null),
    ))->toBe([
        'interested',
        'contacted',
        'purchased',
        'rejected',
        'archived',
    ])->and(array_map(
        static fn (BuyerDecisionState $state): string => $state->value,
        BuyerDecisionState::allowedFrom(BuyerDecisionState::Interested),
    ))->toBe([
        'contacted',
        'purchased',
        'rejected',
        'archived',
    ])->and(array_map(
        static fn (BuyerDecisionState $state): string => $state->value,
        BuyerDecisionState::allowedFrom(BuyerDecisionState::Contacted),
    ))->toBe([
        'purchased',
        'rejected',
        'archived',
    ])->and(array_map(
        static fn (BuyerDecisionState $state): string => $state->value,
        BuyerDecisionState::allowedFrom(BuyerDecisionState::Purchased),
    ))->toBe(['archived'])
        ->and(array_map(
            static fn (BuyerDecisionState $state): string => $state->value,
            BuyerDecisionState::allowedFrom(BuyerDecisionState::Rejected),
        ))->toBe(['interested', 'archived'])
        ->and(array_map(
            static fn (BuyerDecisionState $state): string => $state->value,
            BuyerDecisionState::allowedFrom(BuyerDecisionState::Archived),
        ))->toBe(['interested']);
});

test('same-state and undocumented transitions are rejected', function () {
    expect(BuyerDecisionState::canTransition(
        BuyerDecisionState::Interested,
        BuyerDecisionState::Interested,
    ))->toBeFalse()
        ->and(BuyerDecisionState::canTransition(
            BuyerDecisionState::Contacted,
            BuyerDecisionState::Interested,
        ))->toBeFalse()
        ->and(BuyerDecisionState::canTransition(
            BuyerDecisionState::Purchased,
            BuyerDecisionState::Rejected,
        ))->toBeFalse()
        ->and(BuyerDecisionState::canTransition(
            BuyerDecisionState::Archived,
            BuyerDecisionState::Purchased,
        ))->toBeFalse();
});
