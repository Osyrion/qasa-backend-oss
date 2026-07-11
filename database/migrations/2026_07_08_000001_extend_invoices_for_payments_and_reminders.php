<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the status check — the InvoiceStatus enum already models
        // issued/reminded/credited, but the column has only ever allowed
        // draft/sent/paid/cancelled. The named CHECK constraint is dropped
        // and re-created with the full status list below.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check');
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->change();

            $table->timestamp('last_reminded_at')->nullable()->after('emailed_cc');
            $table->unsignedSmallInteger('reminder_count')->default(0)->after('last_reminded_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status::text IN ('draft', 'issued', 'sent', 'reminded', 'paid', 'cancelled', 'credited'))"
            );
        }

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2)->comment('In the invoice currency');
            $table->date('paid_at');
            $table->string('method', 30)->nullable()->comment('bank_transfer | cash | card | other');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['last_reminded_at', 'reminder_count']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check');
            DB::statement(
                "ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status::text IN ('draft', 'sent', 'paid', 'cancelled'))"
            );
        }
    }
};
