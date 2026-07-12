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
            $table->string('vendor_account_number', 17)->nullable()->comment('Domestic format [prefix-]number');
            $table->string('vendor_bank_code', 4)->nullable();
            $table->string('vendor_iban', 34)->nullable();
            $table->string('vendor_bic', 11)->nullable();
            $table->string('account_source', 10)->nullable()->comment('manual|ocr');
            $table->timestamp('account_verified_at')->nullable();
            $table->string('account_verification_result', 20)->nullable()->comment('published|unpublished|unreliable');
            $table->timestamp('handed_to_payment_at')->nullable();

            $table->index(['user_id', 'handed_to_payment_at']);
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'handed_to_payment_at']);
            $table->dropColumn([
                'vendor_account_number', 'vendor_bank_code', 'vendor_iban', 'vendor_bic',
                'account_source', 'account_verified_at', 'account_verification_result',
                'handed_to_payment_at',
            ]);
        });
    }
};
