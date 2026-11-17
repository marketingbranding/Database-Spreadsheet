<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('successor_booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 24);
            $table->date('effective_date');
            $table->text('reason');
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('source_owner', 16)->default('laravel')->index();
            $table->string('source_key')->unique();
            $table->string('source_hash', 64);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'action']);
            $table->index(['source_booking_id', 'successor_booking_id']);
        });

        Schema::table('psjb_records', function (Blueprint $table) {
            $table->foreignId('utj_source_psjb_id')->nullable()->after('utj_amount')->constrained('psjb_records')->nullOnDelete();
            $table->index('utj_source_psjb_id');
        });
    }

    public function down(): void
    {
        Schema::table('psjb_records', function (Blueprint $table) {
            $table->dropForeign(['utj_source_psjb_id']);
            $table->dropIndex(['utj_source_psjb_id']);
            $table->dropColumn('utj_source_psjb_id');
        });

        Schema::dropIfExists('booking_transitions');
    }
};
