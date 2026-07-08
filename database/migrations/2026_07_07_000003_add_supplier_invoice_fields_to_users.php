<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->comment('Supplier logo printed on invoices');
            $table->string('vat_id', 20)->nullable()->comment('IČ DPH / VAT ID');
            $table->string('website', 150)->nullable();
            $table->text('invoice_footer_text')->nullable();
            $table->text('clockify_api_key')->nullable()->comment('Encrypted');
            $table->string('clockify_workspace_id', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'logo_path',
                'vat_id',
                'website',
                'invoice_footer_text',
                'clockify_api_key',
                'clockify_workspace_id',
            ]);
        });
    }
};
