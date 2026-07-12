<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('reverse_charge_allowed')->default(false)->after('is_vat_payer');
            $table->timestamp('vat_verified_at')->nullable()->comment('Last successful VIES check; persists across grace window fallbacks')->after('reverse_charge_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['reverse_charge_allowed', 'vat_verified_at']);
        });
    }
};
