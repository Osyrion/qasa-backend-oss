<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the enum to plain string so new sources (cnb) fit.
        // ->change() rebuilds the table on sqlite, dropping the inline enum CHECK;
        // allowed values are enforced by the ExchangeRateSource PHP enum.
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->change();
        });
    }
};
