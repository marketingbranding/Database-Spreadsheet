<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_import_issue_id')->nullable()->constrained('import_issues')->nullOnDelete();
            $table->foreignId('supersedes_conflict_id')->nullable()->constrained('import_conflicts')->nullOnDelete();
            $table->string('stable_source_identity');
            $table->string('case_type', 32)->index();
            $table->string('status', 24)->default('OPEN')->index();
            $table->string('severity', 16)->default('warning');
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_key')->nullable();
            $table->string('source_fingerprint', 64);
            $table->string('reviewed_source_fingerprint', 64)->nullable();
            $table->json('source_value')->nullable();
            $table->json('laravel_value')->nullable();
            $table->json('inventory_impact')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_type', 40)->nullable();
            $table->timestamps();

            $table->unique(
                ['project_id', 'stable_source_identity', 'source_fingerprint'],
                'import_conflicts_identity_unique'
            );
            $table->index(['project_id', 'status']);
        });

        Schema::create('import_conflict_lot', function (Blueprint $table) {
            $table->foreignId('import_conflict_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->primary(['import_conflict_id', 'lot_id']);
            $table->index(['lot_id', 'import_conflict_id']);
        });

        Schema::create('import_conflict_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_conflict_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->string('role', 24);
            $table->timestamps();

            $table->unique(
                ['import_conflict_id', 'booking_id', 'role'],
                'import_conflict_booking_role_unique'
            );
        });

        Schema::create('import_conflict_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_conflict_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->text('reason');
            $table->text('evidence');
            $table->string('source_reference')->nullable();
            $table->json('proposed_values')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('request_hash', 64);
            $table->unsignedTinyInteger('pending_guard')->nullable()->default(1);
            $table->string('status', 24)->default('PENDING');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approval_reason')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['import_conflict_id', 'pending_guard'],
                'import_conflict_one_pending_unique'
            );
            $table->index(['project_id', 'status']);
        });

        Schema::create('import_conflict_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_conflict_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_conflict_proposal_id')->nullable()->constrained('import_conflict_proposals')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->text('reason')->nullable();
            $table->string('source_reference')->nullable();
            $table->string('source_fingerprint', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();

            $table->index(['import_conflict_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_conflict_events');
        Schema::dropIfExists('import_conflict_proposals');
        Schema::dropIfExists('import_conflict_bookings');
        Schema::dropIfExists('import_conflict_lot');
        Schema::dropIfExists('import_conflicts');
    }
};
