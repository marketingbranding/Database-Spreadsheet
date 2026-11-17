<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppjb_records', function (Blueprint $table) {
            $table->foreignId('bank_submission_id')->nullable()->after('booking_id')->constrained('bank_submissions')->nullOnDelete();
            $table->foreignId('pemberkasan_id')->nullable()->after('bank_submission_id')->constrained('pemberkasan_records')->nullOnDelete();
            $table->string('source_owner', 16)->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('ppjb_records', function (Blueprint $table) {
            $table->dropIndex(['source_owner']);
            $table->dropUnique(['idempotency_key']);
            $table->dropForeign(['bank_submission_id']);
            $table->dropForeign(['pemberkasan_id']);
            $table->dropColumn(['bank_submission_id', 'pemberkasan_id', 'source_owner', 'idempotency_key']);
        });
    }
};
