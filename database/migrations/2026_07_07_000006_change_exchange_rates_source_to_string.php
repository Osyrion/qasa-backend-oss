<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the enum to plain string so new sources (cnb) fit. Allowed
        // values are enforced by the ExchangeRateSource PHP enum. ->change()
        // leaves the enum's CHECK constraint behind, which
        // 2026_07_10_215115_drop_stale_exchange_rates_source_check drops.
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
