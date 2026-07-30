<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->comment('social|health|income_tax_advance');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->decimal('amount', 12, 2);
            $table->enum('currency', ['CZK', 'EUR', 'USD']);
            $table->date('paid_at');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type', 'paid_at']);
            $table->index(['user_id', 'period_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_payments');
    }
};
