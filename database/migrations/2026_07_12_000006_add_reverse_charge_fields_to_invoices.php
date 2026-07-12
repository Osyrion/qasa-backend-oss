<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('reverse_charge')->default(false)->after('discount_amount');
            $table->string('reverse_charge_mode', 20)->nullable()->after('reverse_charge');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE invoices ADD CONSTRAINT invoices_reverse_charge_mode_check CHECK (reverse_charge_mode IS NULL OR reverse_charge_mode::text IN ('domestic', 'eu'))"
            );
            DB::statement(
                'ALTER TABLE invoices ADD CONSTRAINT invoices_reverse_charge_mode_presence_check CHECK (reverse_charge = false OR reverse_charge_mode IS NOT NULL)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_reverse_charge_mode_check');
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_reverse_charge_mode_presence_check');
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['reverse_charge', 'reverse_charge_mode']);
        });
    }
};
