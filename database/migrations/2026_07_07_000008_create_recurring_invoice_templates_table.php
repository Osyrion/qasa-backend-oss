<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoice_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $table->string('name');

            // Schedule
            $table->enum('status', ['active', 'paused', 'expired'])->default('active');
            $table->enum('period', ['monthly', 'quarterly', 'semiannually', 'yearly']);
            $table->unsignedTinyInteger('day_of_month')->default(1)
                ->comment('1-28, ignored when last_day_of_month');
            $table->boolean('last_day_of_month')->default(false);
            $table->date('first_issue_date');
            $table->date('end_date')->nullable()->comment('Template expires once next_run_date passes it');
            $table->date('next_run_date');
            $table->date('last_generated_at')->nullable()->comment('issued_at of the last generated invoice');

            // Invoice metadata copied to each generated invoice
            $table->enum('type', ['invoice', 'proforma'])->default('invoice');
            $table->enum('currency', ['CZK', 'EUR', 'USD']);
            $table->unsignedSmallInteger('due_days')->default(14);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->enum('tax_date_mode', ['issue_date', 'previous_month_end'])->default('issue_date')
                ->comment('How taxable_supply_at (DUZP) is derived from issued_at');
            $table->text('note_above')->nullable();
            $table->text('note_below')->nullable()->comment('Copied to invoices.note');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'next_run_date']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('recurring_invoice_template_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('recurring_invoice_templates')->cascadeOnDelete();

            $table->string('description', 500);
            $table->decimal('quantity', 10, 3);
            $table->string('unit', 20)->default('ks');
            $table->decimal('unit_price', 10, 2)->comment('Excl. VAT');
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['template_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_template_items');
        Schema::dropIfExists('recurring_invoice_templates');
    }
};
