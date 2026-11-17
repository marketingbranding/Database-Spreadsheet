<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pemberkasan_records', function (Blueprint $table) {
            $table->string('source_owner', 16)->default('sheets')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_current')->default(true)->index();
            $table->index(['booking_id', 'is_current']);
            $table->unique(['booking_id', 'file_number']);
        });
    }

    public function down(): void
    {
        Schema::table('pemberkasan_records', function (Blueprint $table) {
            $table->dropUnique(['booking_id', 'file_number']);
            $table->dropIndex(['booking_id', 'is_current']);
            $table->dropColumn(['source_owner', 'revision', 'is_current']);
        });
    }
};
