<?php

namespace App\Market;

use App\Enums\Markets\ContinentCode;
use LogicException;

final class CountryReferenceData
{
    /**
     * ISO 3166-1 alpha-2 assignments grouped by the UN M49 geographic convention.
     *
     * @return array<string, ContinentCode>
     */
    public static function continentByCountry(): array
    {
        $groups = [
            ContinentCode::Africa->value => self::codes(
                'DZ AO BJ BW BF BI CV CM CF TD KM CG CD CI DJ EG GQ ER SZ ET GA GM GH GN GW KE LS LR LY MG MW ML MR MU MA MZ NA NE NG RW ST SN SC SL SO ZA SS SD TZ TG TN UG EH ZM ZW IO RE SH YT',
            ),
            ContinentCode::Antarctica->value => self::codes('AQ BV GS HM TF'),
            ContinentCode::Asia->value => self::codes(
                'AF AM AZ BH BD BT BN KH CN CY GE HK IN ID IR IQ IL JP JO KZ KP KR KW KG LA LB MO MY MV MN MM NP OM PK PS PH QA SA SG LK SY TW TJ TH TL TM TR AE UZ VN YE',
            ),
            ContinentCode::Europe->value => self::codes(
                'AL AD AT AX BY BE BA BG HR CZ DK EE FO FI FR DE GI GR GG VA HU IS IE IM IT JE LV LI LT LU MT MD MC ME NL MK NO PL PT RO RU SM RS SK SI ES SJ SE CH UA GB',
            ),
            ContinentCode::NorthAmerica->value => self::codes(
                'AI AG AW BS BB BZ BM BQ VG CA KY CR CU CW DM DO SV GL GD GP GT HT HN JM MQ MX MS NI PA PR BL KN LC MF PM VC SX TT TC US VI',
            ),
            ContinentCode::Oceania->value => self::codes(
                'AS AU CX CC CK FJ PF GU KI MH FM NR NC NZ NU NF MP PW PG PN WS SB TK TO TV UM VU WF',
            ),
            ContinentCode::SouthAmerica->value => self::codes(
                'AR BO BR CL CO EC FK GF GY PY PE SR UY VE',
            ),
        ];

        $lookup = [];

        foreach ($groups as $continent => $countries) {
            foreach ($countries as $country) {
                if (isset($lookup[$country])) {
                    throw new LogicException("Country {$country} has more than one continent.");
                }

                $lookup[$country] = ContinentCode::from($continent);
            }
        }

        return $lookup;
    }

    /**
     * @return list<string>
     */
    private static function codes(string $codes): array
    {
        return explode(' ', $codes);
    }
}
