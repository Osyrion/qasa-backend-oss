<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number', 30)->comment('e.g. FA-2024-001');
            $table->enum('status', ['draft', 'sent', 'paid', 'cancelled'])->default('draft');
            $table->date('issued_at');
            $table->date('due_at');
            $table->enum('currency', ['CZK', 'EUR', 'USD']);
            $table->decimal('exchange_rate_snapshot', 12, 6)->nullable()
                ->comment('Rate to user default_currency at time of issue');

            // Totals — denormalized for performance and historical accuracy
            $table->decimal('subtotal', 10, 2)->default(0)->comment('Sum of all items excl. VAT');
            $table->decimal('vat_amount', 10, 2)->default(0)->comment('Sum of all VAT amounts');
            $table->decimal('total', 10, 2)->default(0)->comment('subtotal + vat_amount');

            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'invoice_number']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'issued_at']);
            $table->index(['client_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();

            // Source references — both nullable, item can be manually added
            $table->foreignUuid('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('time_entry_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');
            $table->decimal('quantity', 10, 3);
            $table->string('unit', 20)->default('ks');
            $table->decimal('unit_price', 10, 2)->comment('Excl. VAT');
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 10, 2)->default(0);
            $table->decimal('total_excl_vat', 10, 2);
            $table->decimal('total_incl_vat', 10, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['invoice_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
