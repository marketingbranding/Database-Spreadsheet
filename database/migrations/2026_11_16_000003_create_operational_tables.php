<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyers', function (Blueprint $table) {
            $table->id();
            $table->string('source_key')->unique();
            $table->string('source_customer_id', 64)->nullable()->index();
            $table->string('name');
            $table->text('nik_encrypted')->nullable();
            $table->string('nik_hash', 64)->nullable()->index();
            $table->string('nik_last4', 4)->nullable();
            $table->text('phone_encrypted')->nullable();
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->timestamps();
        });

        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('source_id_kavling')->nullable();
            $table->decimal('building_area', 8, 2)->nullable();
            $table->decimal('land_area', 8, 2)->nullable();
            $table->decimal('klt_area', 8, 2)->nullable();
            $table->decimal('build_progress', 6, 2)->nullable();
            $table->date('ready_date')->nullable();
            $table->string('order_status')->nullable();
            $table->boolean('historical_akad_before_2026')->default(false);
            $table->string('historical_marker')->nullable();
            $table->string('opening_status')->nullable();
            $table->string('source_key')->unique();
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'code']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained()->restrictOnDelete();
            $table->string('source_booking_key')->unique();
            $table->string('source_transaction_id', 64)->nullable()->index();
            $table->string('psjb_number', 96)->nullable()->index();
            $table->date('booking_date')->nullable()->index();
            $table->string('payment_method', 24)->nullable();
            $table->string('sales_name')->nullable();
            $table->string('coordinator_name')->nullable();
            $table->decimal('base_price', 15, 2)->nullable();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->string('current_stage', 24)->default('PSJB')->index();
            $table->string('decision_stage', 24)->nullable()->index();
            $table->date('decision_date')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('predecessor_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('transfer_type', 24)->nullable();
            $table->boolean('is_current_holder')->default(false)->index();
            $table->date('akad_date')->nullable()->index();
            $table->date('bast_date')->nullable()->index();
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['lot_id', 'is_current_holder']);
        });

        Schema::create('psjb_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->unique();
            $table->string('psjb_number', 96)->nullable()->index();
            $table->date('psjb_date')->nullable();
            $table->string('sales_name')->nullable();
            $table->string('coordinator_name')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->date('utj_date')->nullable();
            $table->decimal('utj_amount', 15, 2)->nullable();
            $table->decimal('dp_all_in', 15, 2)->nullable();
            $table->unsignedInteger('installment_count')->nullable();
            $table->string('payment_method', 24)->nullable();
            $table->string('source_status', 32)->nullable();
            $table->text('notes')->nullable();
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('bi_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->unique();
            $table->string('bi_number', 96)->nullable()->index();
            $table->date('check_date')->nullable();
            $table->string('result', 32)->nullable();
            $table->string('decision', 32)->nullable();
            $table->boolean('withdrawn_flag')->default(false);
            $table->boolean('cancelled_flag')->default(false);
            $table->boolean('moved_lot_flag')->default(false);
            $table->boolean('name_change_flag')->default(false);
            $table->text('notes')->nullable();
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('pemberkasan_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->unique();
            $table->string('file_number', 96)->nullable()->index();
            $table->date('received_date')->nullable();
            $table->string('bank')->nullable();
            $table->string('bank_unit')->nullable();
            $table->string('status', 48)->nullable();
            $table->boolean('withdrawn_flag')->default(false);
            $table->boolean('cancelled_flag')->default(false);
            $table->boolean('moved_lot_flag')->default(false);
            $table->boolean('name_change_flag')->default(false);
            $table->text('notes')->nullable();
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('bank_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->unique();
            $table->string('file_number', 96)->nullable()->index();
            $table->string('decision_number', 96)->nullable()->index();
            $table->unsignedInteger('submission_sequence')->nullable();
            $table->string('bank')->nullable();
            $table->string('bank_unit')->nullable();
            $table->date('submitted_date')->nullable();
            $table->date('decision_date')->nullable();
            $table->string('result', 24)->nullable()->index();
            $table->decimal('approved_amount', 15, 2)->nullable();
            $table->string('tenor', 32)->nullable();
            $table->boolean('withdrawn_flag')->default(false);
            $table->boolean('cancelled_flag')->default(false);
            $table->boolean('moved_lot_flag')->default(false);
            $table->boolean('name_change_flag')->default(false);
            $table->text('notes')->nullable();
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('ppjb_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->unique();
            $table->string('ppjb_number', 96)->nullable()->index();
            $table->date('signed_date')->nullable();
            $table->string('dp_status', 48)->nullable();
            $table->boolean('withdrawn_flag')->default(false);
            $table->boolean('cancelled_flag')->default(false);
            $table->boolean('moved_lot_flag')->default(false);
            $table->boolean('name_change_flag')->default(false);
            $table->text('notes')->nullable();
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('akad_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->unique();
            $table->string('akad_number', 96)->nullable()->index();
            $table->date('akad_date')->nullable()->index();
            $table->string('quality', 64)->nullable();
            $table->boolean('withdrawn_flag')->default(false);
            $table->boolean('cancelled_flag')->default(false);
            $table->boolean('moved_lot_flag')->default(false);
            $table->boolean('name_change_flag')->default(false);
            $table->text('notes')->nullable();
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('bast_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->unique();
            $table->string('bast_number', 96)->nullable()->index();
            $table->date('bast_date')->nullable()->index();
            $table->text('notes')->nullable();
            $table->string('source_tab')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'bast_records', 'akad_records', 'ppjb_records', 'bank_submissions', 'pemberkasan_records',
            'bi_checks', 'psjb_records', 'bookings', 'lots', 'buyers',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
