<?php

namespace App\Enums\Markets;

enum ContinentCode: string
{
    case Africa = 'AF';
    case Antarctica = 'AN';
    case Asia = 'AS';
    case Europe = 'EU';
    case NorthAmerica = 'NA';
    case Oceania = 'OC';
    case SouthAmerica = 'SA';

    public function label(): string
    {
        return match ($this) {
            self::Africa => 'Africa',
            self::Antarctica => 'Antarctica',
            self::Asia => 'Asia',
            self::Europe => 'Europe',
            self::NorthAmerica => 'North America',
            self::Oceania => 'Oceania',
            self::SouthAmerica => 'South America',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Africa => 10,
            self::Asia => 20,
            self::Europe => 30,
            self::NorthAmerica => 40,
            self::SouthAmerica => 50,
            self::Oceania => 60,
            self::Antarctica => 70,
        };
    }
}
