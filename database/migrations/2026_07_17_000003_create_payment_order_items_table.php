<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_invoice_id')->nullable()
                ->constrained()->nullOnDelete()
                ->comment('Snapshot survives invoice deletion');

            $table->string('vendor_name');
            $table->string('supplier_invoice_number', 60);
            $table->string('account_number', 17)->nullable();
            $table->string('bank_code', 4)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
            $table->string('variable_symbol', 10)->nullable();
            $table->decimal('amount', 12, 2);
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('payment_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_order_items');
    }
};
