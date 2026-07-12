<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bank_account_id')->nullable()->constrained()->nullOnDelete();

            $table->json('payer_snapshot')->comment('Frozen payer account: label, number, IBAN, BIC, currency');
            $table->enum('currency', ['CZK', 'EUR', 'USD']);
            $table->date('due_date');
            $table->string('constant_symbol', 4)->nullable();
            $table->text('note')->nullable();

            $table->integer('items_count');
            $table->decimal('total_amount', 12, 2);
            $table->boolean('marked_paid')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
