<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoice_vat_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_invoice_id')->constrained()->cascadeOnDelete();

            $table->decimal('vat_rate', 5, 2);
            $table->decimal('base', 12, 2);
            $table->decimal('vat_amount', 12, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index('supplier_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_vat_lines');
    }
};
