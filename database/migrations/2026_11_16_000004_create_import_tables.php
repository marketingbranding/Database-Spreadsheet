<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 16); // dry_run | apply
            $table->string('source', 160); // google:<spreadsheet> | fixture:<file>
            $table->string('status', 24)->default('running');
            $table->string('spreadsheet_id', 128)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('import_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('severity', 16)->default('warning')->index();
            $table->string('code', 64)->index();
            $table->text('message');
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_key')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_issues');
        Schema::dropIfExists('import_batches');
    }
};
