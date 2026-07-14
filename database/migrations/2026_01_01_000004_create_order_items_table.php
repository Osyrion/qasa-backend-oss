<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Predefined units — stored as string to allow custom values.
        // App layer validates against known list and passes through custom values.
        // Known: ks, hod, deň, mesiac, km, l, dl, ml, kg, g, m, m2, m3

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();

            // Type determines UI rendering and report grouping
            $table->enum('type', ['service', 'product', 'time'])->default('service')
                ->comment('service=úkon, product=tovar/materiál, time=čas');

            $table->string('description');

            // Quantity + unit
            $table->decimal('quantity', 10, 3)->default(1);
            $table->string('unit', 20)->default('ks')
                ->comment('ks|hod|deň|mesiac|km|l|dl|ml|kg|g|m|m2|m3 or custom');

            // Pricing
            $table->decimal('unit_price', 10, 2)->comment('Excl. VAT');
            $table->decimal('vat_rate', 5, 2)->default(0)->comment('e.g. 0, 10, 20, 21, 23');
            $table->decimal('vat_amount', 10, 2)->default(0)->comment('Computed: quantity * unit_price * vat_rate / 100');
            $table->decimal('total_excl_vat', 10, 2)->comment('quantity * unit_price');
            $table->decimal('total_incl_vat', 10, 2)->comment('total_excl_vat + vat_amount');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'type']);
            $table->index(['order_id', 'sort_order']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
