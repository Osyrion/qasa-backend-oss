<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('converted_from_currency', 3)->nullable()
                ->comment('Order currency the invoice was converted from, when it differs from the invoice currency');
            $table->decimal('conversion_rate', 12, 6)->nullable()
                ->comment('Cross rate applied from converted_from_currency to the invoice currency at generation time');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['converted_from_currency', 'conversion_rate']);
        });
    }
};
