<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bi_checks', function (Blueprint $table) {
            $table->string('source_owner', 16)->default('sheets')->index();
            $table->index(['project_id', 'source_owner']);
        });
    }

    public function down(): void
    {
        Schema::table('bi_checks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'source_owner']);
            $table->dropColumn('source_owner');
        });
    }
};
