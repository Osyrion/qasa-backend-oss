<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->constrained()->cascadeOnDelete();

            $table->string('description', 500);
            $table->decimal('quantity', 10, 3);
            $table->string('unit', 20)->default('ks');
            $table->decimal('unit_price', 10, 2)->comment('Excl. VAT');
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 10, 2)->default(0);
            $table->decimal('total_excl_vat', 10, 2)->default(0);
            $table->decimal('total_incl_vat', 10, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['quote_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};
