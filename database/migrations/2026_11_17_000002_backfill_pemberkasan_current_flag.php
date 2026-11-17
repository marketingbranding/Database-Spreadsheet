<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Fixes 2026_11_16_000008 defaulting is_current=true on every pre-existing row
    // instead of only the latest revision per booking. Idempotent: safe to rerun.
    // Portable subqueries (no UPDATE...JOIN) so this runs on MySQL and the sqlite test driver alike.
    public function up(): void
    {
        DB::statement('update pemberkasan_records set is_current = false');
        DB::statement(
            'update pemberkasan_records set is_current = true where id in (
                select pr.id from pemberkasan_records pr
                where pr.revision = (select max(revision) from pemberkasan_records where booking_id = pr.booking_id)
                  and pr.id = (
                      select max(id) from pemberkasan_records
                      where booking_id = pr.booking_id and revision = pr.revision
                  )
            )'
        );
    }

    public function down(): void
    {
        // Data-fixing migration; no safe reverse.
    }
};
