<?php

namespace App\Enums\Sell;

enum SalePortfolioEventType: string
{
    case Published = 'published';
    case PriceChanged = 'price_changed';
    case Reserved = 'reserved';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Relisted = 'relisted';

    /**
     * @return list<self>
     */
    public static function allowedFrom(SalePortfolioStatus $status): array
    {
        return match ($status) {
            SalePortfolioStatus::Draft => [self::Published],
            SalePortfolioStatus::Listed => [
                self::PriceChanged,
                self::Reserved,
                self::Withdrawn,
                self::Expired,
            ],
            SalePortfolioStatus::Reserved => [
                self::Relisted,
                self::Withdrawn,
                self::Expired,
            ],
            SalePortfolioStatus::Withdrawn,
            SalePortfolioStatus::Expired => [self::Relisted],
        };
    }

    public static function canApply(
        SalePortfolioStatus $status,
        self $event,
    ): bool {
        return in_array($event, self::allowedFrom($status), true);
    }

    public function nextStatus(): SalePortfolioStatus
    {
        return match ($this) {
            self::Published,
            self::PriceChanged,
            self::Relisted => SalePortfolioStatus::Listed,
            self::Reserved => SalePortfolioStatus::Reserved,
            self::Withdrawn => SalePortfolioStatus::Withdrawn,
            self::Expired => SalePortfolioStatus::Expired,
        };
    }

    public function requiresPublicationSnapshot(): bool
    {
        return in_array($this, [
            self::Published,
            self::Relisted,
        ], true);
    }

    public function requiresPrice(): bool
    {
        return in_array($this, [
            self::Published,
            self::PriceChanged,
            self::Relisted,
        ], true);
    }
}
