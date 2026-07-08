<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('level', ['user', 'client', 'order'])->comment('Scope of the rate; more specific level wins');
            $table->foreignUuid('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('rate', 10, 2)->nullable()->comment('Rate per billing unit, excl. VAT; null = tombstone — level stops applying from valid_from');
            $table->enum('currency', ['CZK', 'EUR', 'USD'])->nullable()->comment('null = inherited effective currency');
            $table->date('valid_from')->comment('Append-only history; newest valid_from <= work date wins within a level');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'level', 'client_id', 'order_id', 'valid_from'], 'unique_rate_per_scope_from');
            $table->index(['user_id', 'level', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rates');
    }
};
