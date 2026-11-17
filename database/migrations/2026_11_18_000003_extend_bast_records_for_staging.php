<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bast_records', function (Blueprint $table) {
            $table->foreignId('akad_id')->nullable()->after('booking_id')->constrained('akad_records')->restrictOnDelete();
            $table->string('source_owner', 16)->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('bast_records', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['source_owner']);
            $table->dropForeign(['akad_id']);
            $table->dropColumn(['akad_id', 'source_owner', 'idempotency_key']);
        });
    }
};
