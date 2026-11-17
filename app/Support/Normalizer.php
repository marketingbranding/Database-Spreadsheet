<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class Normalizer
{
    private const MONTHS = [
        'jan' => 1, 'januari' => 1, 'january' => 1,
        'feb' => 2, 'februari' => 2, 'february' => 2,
        'mar' => 3, 'maret' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4,
        'mei' => 5, 'may' => 5,
        'jun' => 6, 'juni' => 6, 'june' => 6,
        'jul' => 7, 'juli' => 7, 'july' => 7,
        'agu' => 8, 'agt' => 8, 'agustus' => 8, 'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'okt' => 10, 'oktober' => 10, 'oct' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11,
        'des' => 12, 'desember' => 12, 'dec' => 12, 'december' => 12,
    ];

    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : null;
        }

        $value = trim((string) $value);
        if ($value === '' || $value === '-' || Str::lower($value) === 'null') {
            return null;
        }

        return $value;
    }

    public static function blankPlaceholder(mixed $value): bool
    {
        return in_array(
            Str::lower(trim((string) $value)),
            ['', '-', 'sumber kosong', 'kosong', 'tidak ada', 'n a', 'na', 'null'],
            true,
        );
    }

    public static function header(mixed $value): string
    {
        $value = Str::lower((string) $value);
        $value = preg_replace('/\[[^\]]*\]/', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        $value = Str::lower(trim((string) $value));

        return in_array($value, ['1', 'true', 'ya', 'yes', 'y', 'v', 'checked', 'centang', '✓'], true);
    }

    public static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $s = trim((string) $value);
        $s = preg_replace('/[^0-9,.\-]/u', '', $s) ?? '';
        if ($s === '' || $s === '-') {
            return null;
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            if (strrpos($s, ',') > strrpos($s, '.')) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma) {
            // "1,234,567" — repeated commas are thousands separators; a single
            // comma may be a decimal separator.
            if (substr_count($s, ',') > 1) {
                $s = str_replace(',', '', $s);
            } else {
                $s = str_replace(',', '.', $s);
            }
        } elseif ($hasDot) {
            $parts = explode('.', $s);
            $last = end($parts);
            if (count($parts) > 2 || strlen($last) === 3) {
                $s = str_replace('.', '', $s);
            }
        }

        return is_numeric($s) ? (float) $s : null;
    }

    public static function integer(mixed $value): ?int
    {
        $n = self::number($value);

        return $n === null ? null : (int) round($n);
    }

    public static function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 1000 && $serial < 80000) {
                return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')
                    ->addDays((int) floor($serial));
            }
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (in_array(Str::lower($s), ['sumber kosong', 'kosong', 'tidak ada', 'n a', 'na'], true)) {
            return null;
        }
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = str_replace(['.', ','], ' ', $s);
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);

        $clean = Str::lower($s);
        $clean = str_replace(['agustus', 'agu', 'agt'], 'agu', $clean);

        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/', $s, $m)) {
            return self::safeDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{2,4})/', $s, $m)) {
            $year = (int) $m[3];
            $year += $year < 100 ? 2000 : 0;

            return self::safeDate($year, (int) $m[2], (int) $m[1]);
        }

        if (preg_match('/^(\d{1,2})\s+([a-z]+)\s+(\d{2,4})$/u', $clean, $m)) {
            $month = self::MONTHS[$m[2]] ?? null;
            $year = (int) $m[3];
            $year += $year < 100 ? 2000 : 0;

            return $month ? self::safeDate($year, $month, (int) $m[1]) : null;
        }

        if (preg_match('/^([a-z]+)\s+(\d{1,2})\s+(\d{2,4})$/u', $clean, $m)) {
            $month = self::MONTHS[$m[1]] ?? null;
            $year = (int) $m[3];
            $year += $year < 100 ? 2000 : 0;

            return $month ? self::safeDate($year, $month, (int) $m[2]) : null;
        }

        return null;
    }

    private static function safeDate(int $year, int $month, int $day): ?CarbonImmutable
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'Asia/Jakarta');
    }

    /**
     * Extract a date from a legacy dropdown label such as
     * "Reychan Eka Pratama | G09 | KPR | 14 Apr 2026".
     */
    public static function dateFromLabel(?string $label): ?CarbonImmutable
    {
        if (! $label) {
            return null;
        }

        $parts = array_map('trim', explode('|', $label));
        foreach (array_reverse($parts) as $part) {
            $date = self::date($part);
            if ($date) {
                return $date;
            }
        }

        return null;
    }

    public static function lotCode(mixed $value, ?string $projectName = null): ?string
    {
        $value = self::text($value);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        if ($projectName && Str::startsWith(Str::lower($value), Str::lower($projectName))) {
            $value = trim(substr($value, strlen($projectName)));
            $value = ltrim($value, "-–—:| ");
        }

        if (str_contains($value, '-')) {
            $tail = trim((string) substr($value, (int) strrpos($value, '-') + 1));
            if ($tail !== '' && preg_match('/^[A-Za-z0-9.\/]+$/', $tail)) {
                $value = $tail;
            }
        }

        $value = Str::upper(trim($value));

        return $value === '' ? null : $value;
    }

    public static function nameKey(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        $name = Str::lower($name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    public static function nikHash(?string $nik): ?string
    {
        if (! $nik) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $nik) ?? '';

        return $digits === '' ? null : hash('sha256', $digits);
    }

    public static function nikLast4(?string $nik): ?string
    {
        if (! $nik) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $nik) ?? '';

        return $digits === '' ? null : substr($digits, -4);
    }

    public static function slug(?string $value): string
    {
        return Str::slug((string) $value) ?: 'unknown';
    }
}
