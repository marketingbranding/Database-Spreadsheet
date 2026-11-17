<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('akad_records', function (Blueprint $table) {
            $table->foreignId('ppjb_id')->nullable()->after('booking_id')->constrained('ppjb_records')->restrictOnDelete();
            $table->string('source_owner', 16)->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('akad_records', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['source_owner']);
            $table->dropForeign(['ppjb_id']);
            $table->dropColumn(['ppjb_id', 'source_owner', 'idempotency_key']);
        });
    }
};
