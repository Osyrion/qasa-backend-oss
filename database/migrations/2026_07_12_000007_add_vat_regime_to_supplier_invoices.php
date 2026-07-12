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
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->string('vat_regime', 20)->default('domestic')->after('status');
            $table->decimal('self_assessed_vat_amount', 12, 2)->default(0)->after('total');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_vat_regime_check CHECK (vat_regime::text IN ('domestic', 'eu_reverse_charge', 'import'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_invoices DROP CONSTRAINT IF EXISTS supplier_invoices_vat_regime_check');
        }

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn(['vat_regime', 'self_assessed_vat_amount']);
        });
    }
};
