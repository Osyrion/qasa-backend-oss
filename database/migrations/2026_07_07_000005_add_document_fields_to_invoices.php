<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('type', ['invoice', 'proforma', 'credit_note', 'storno'])
                ->default('invoice')
                ->after('invoice_number');
            $table->foreignUuid('related_invoice_id')
                ->nullable()
                ->after('type')
                ->comment('Original invoice for credit_note/storno')
                ->constrained('invoices')
                ->nullOnDelete();
            $table->date('taxable_supply_at')->nullable()->after('issued_at')->comment('DUZP');
            $table->string('variable_symbol', 10)->nullable()->after('due_at');
            $table->foreignUuid('bank_account_id')
                ->nullable()
                ->after('variable_symbol')
                ->constrained('bank_accounts')
                ->nullOnDelete();
            $table->json('bank_account_snapshot')->nullable()->comment('Frozen at issue');
            $table->json('supplier_snapshot')->nullable()->comment('Frozen at issue');
            $table->json('client_snapshot')->nullable()->comment('Frozen at issue');
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0)->comment('Computed from discount_percent');

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'type']);
            $table->dropConstrainedForeignId('related_invoice_id');
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn([
                'type',
                'taxable_supply_at',
                'variable_symbol',
                'bank_account_snapshot',
                'supplier_snapshot',
                'client_snapshot',
                'discount_percent',
                'discount_amount',
            ]);
        });
    }
};
