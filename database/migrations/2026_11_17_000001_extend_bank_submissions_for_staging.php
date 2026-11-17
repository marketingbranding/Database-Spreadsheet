<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_submissions', function (Blueprint $table) {
            $table->foreignId('pemberkasan_id')->nullable()->after('project_id')->constrained('pemberkasan_records')->nullOnDelete();
            $table->string('source_owner', 16)->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->index(['booking_id', 'pemberkasan_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_submissions', function (Blueprint $table) {
            $table->dropIndex(['booking_id', 'pemberkasan_id']);
            $table->dropIndex(['source_owner']);
            $table->dropUnique(['idempotency_key']);
            $table->dropForeign(['pemberkasan_id']);
            $table->dropColumn(['pemberkasan_id', 'source_owner', 'idempotency_key']);
        });
    }
};
