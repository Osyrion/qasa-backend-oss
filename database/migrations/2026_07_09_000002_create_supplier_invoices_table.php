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
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->comment('Vendor (is_vendor = true)')->constrained()->restrictOnDelete();

            $table->string('internal_number', 30)->comment('Our internal reference number, e.g. DF-2026-001');
            $table->string('supplier_invoice_number', 60)->comment('Original document number as issued by the vendor');
            $table->string('variable_symbol', 10)->nullable()->comment('For our outgoing payment');

            $table->string('status', 20)->default('draft');

            $table->date('issued_at');
            $table->date('taxable_supply_at')->nullable()->comment('DUZP');
            $table->date('due_at')->nullable();
            $table->date('received_at')->nullable();
            $table->date('paid_at')->nullable();

            $table->enum('currency', ['CZK', 'EUR', 'USD'])->default('EUR');
            $table->decimal('exchange_rate', 12, 6)->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->json('vendor_snapshot')->nullable()->comment('Frozen at received');
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'internal_number']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'issued_at']);
            $table->index(['client_id', 'status']);
            $table->index(['user_id', 'client_id', 'supplier_invoice_number']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_status_check CHECK (status::text IN ('draft', 'received', 'booked', 'paid', 'cancelled'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
    }
};
