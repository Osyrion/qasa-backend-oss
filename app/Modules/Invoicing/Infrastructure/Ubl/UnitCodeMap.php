<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ubl;

/**
 * `invoice_items.unit` is a free string carrying Slovak/Czech abbreviations
 * ("ks", "hod", "deň"); UBL requires a UN/ECE Recommendation 20 code.
 *
 * Unknown units fall back to H87 (piece) rather than being emitted as-is:
 * unitCode is mandatory and a value outside the code list fails validation
 * at the receiving end, where nobody can fix it. A wrong-but-valid unit on
 * an otherwise correct invoice is the lesser failure.
 */
final class UnitCodeMap
{
    private const FALLBACK = 'H87';

    /** @var array<string, string> */
    private const MAP = [
        'ks' => 'H87',
        'kus' => 'H87',
        'hod' => 'HUR',
        'h' => 'HUR',
        'deň' => 'DAY',
        'den' => 'DAY',
        'dní' => 'DAY',
        'mesiac' => 'MON',
        'měsíc' => 'MON',
        'km' => 'KMT',
        'l' => 'LTR',
        'dl' => 'DLT',
        'ml' => 'MLT',
        'kg' => 'KGM',
        'g' => 'GRM',
        'm' => 'MTR',
        'm2' => 'MTK',
        'm3' => 'MTQ',
    ];

    public static function toUnece(string $unit): string
    {
        return self::MAP[mb_strtolower(trim($unit))] ?? self::FALLBACK;
    }
}
