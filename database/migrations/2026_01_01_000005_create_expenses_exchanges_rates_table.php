<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('category', 50)->comment('office|travel|software|hardware|marketing|other');
            $table->decimal('amount', 10, 2);
            $table->enum('currency', ['CZK', 'EUR', 'USD']);
            $table->date('date');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'date']);
            $table->index(['user_id', 'category']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('base_currency', ['CZK', 'EUR', 'USD']);
            $table->enum('target_currency', ['CZK', 'EUR', 'USD']);
            $table->decimal('rate', 12, 6);
            $table->date('date');
            $table->enum('source', ['manual', 'ecb', 'fixer'])->default('manual');
            $table->timestamps();

            $table->unique(['user_id', 'base_currency', 'target_currency', 'date'], 'unique_rate_per_day');
            $table->index(['base_currency', 'target_currency', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('expenses');
    }
};
