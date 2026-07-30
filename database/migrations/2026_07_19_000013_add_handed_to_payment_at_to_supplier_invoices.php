<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            // "Handed to payment" is a separate dimension from status — an
            // invoice in a payment-order batch is not paid yet, it merely
            // sits in an exported batch awaiting bank processing.
            $table->timestamp('handed_to_payment_at')->nullable();

            $table->index(['user_id', 'handed_to_payment_at']);
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'handed_to_payment_at']);
            $table->dropColumn('handed_to_payment_at');
        });
    }
};
