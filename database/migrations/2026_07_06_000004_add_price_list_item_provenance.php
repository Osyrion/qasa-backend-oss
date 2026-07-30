<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // FK added by the Pricing module; the column stays in core so an
            // OSS order line has the same shape as a SaaS one.
            $table->uuid('price_list_item_id')->nullable()->after('order_id');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->uuid('price_list_item_id')->nullable()->after('time_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('price_list_item_id');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('price_list_item_id');
        });
    }
};
