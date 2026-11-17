<?php

namespace Tests\Unit;

use App\Support\Normalizer;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    public function test_dates_in_indonesian_and_english_format(): void
    {
        $this->assertSame('2026-04-15', Normalizer::date('15 Apr 2026')?->toDateString());
        $this->assertSame('2026-04-15', Normalizer::date('2026-04-15')?->toDateString());
        $this->assertSame('2026-12-31', Normalizer::date('31 Desember 2026')?->toDateString());
        $this->assertSame('2025-08-17', Normalizer::date('17 Agustus 2025')?->toDateString());
        $this->assertSame('2026-01-05', Normalizer::date('05/01/2026')?->toDateString());
        $this->assertNull(Normalizer::date('bukan tanggal'));
        $this->assertNull(Normalizer::date(''));
        $this->assertNull(Normalizer::date(null));
    }

    public function test_excel_serial_dates(): void
    {
        // 2026-04-15 as an Excel serial (1899-12-30 epoch).
        $this->assertSame('2026-04-15', Normalizer::date(46127)?->toDateString());
    }

    public function test_numbers_with_indonesian_formatting(): void
    {
        $this->assertSame(1234567.0, Normalizer::number('1.234.567'));
        $this->assertSame(1234567.5, Normalizer::number('1.234.567,50'));
        $this->assertSame(1234567.0, Normalizer::number('1,234,567'));
        $this->assertSame(150000000.0, Normalizer::number('Rp 150.000.000'));
        $this->assertNull(Normalizer::number('-'));
        $this->assertNull(Normalizer::number(''));
    }

    public function test_booleans_recognize_checkboxes(): void
    {
        $this->assertTrue(Normalizer::bool('1'));
        $this->assertTrue(Normalizer::bool(true));
        $this->assertTrue(Normalizer::bool('✓'));
        $this->assertTrue(Normalizer::bool('Ya'));
        $this->assertFalse(Normalizer::bool('0'));
        $this->assertFalse(Normalizer::bool(''));
        $this->assertFalse(Normalizer::bool('Tidak'));
    }

    public function test_text_treats_blanks_as_null(): void
    {
        $this->assertSame('Data', Normalizer::text(' Data '));
        $this->assertNull(Normalizer::text('-'));
        $this->assertNull(Normalizer::text(''));
        $this->assertNull(Normalizer::text(null));
    }

    public function test_header_normalization_strips_sys_markers(): void
    {
        $this->assertSame('id transaksi', Normalizer::header('[SYS] ID Transaksi'));
        $this->assertSame('tanggal psjb', Normalizer::header('Tanggal PSJB'));
        $this->assertSame('mundur', Normalizer::header("Mundur?\n"));
    }

    public function test_date_from_label_extracts_trailing_date(): void
    {
        $this->assertSame(
            '2026-04-14',
            Normalizer::dateFromLabel('Orang 001 | G09 | KPR | 14 Apr 2026')?->toDateString(),
        );
        $this->assertNull(Normalizer::dateFromLabel(null));
        $this->assertNull(Normalizer::dateFromLabel('no date here'));
    }
}
