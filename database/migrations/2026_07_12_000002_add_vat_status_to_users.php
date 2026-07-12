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
        Schema::table('users', function (Blueprint $table) {
            $table->string('vat_status', 20)->default('non_payer')->after('is_vat_payer');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE users ADD CONSTRAINT users_vat_status_check CHECK (vat_status::text IN ('non_payer', 'identified', 'payer'))"
            );
        }

        DB::table('users')->where('is_vat_payer', true)->update(['vat_status' => 'payer']);
        DB::table('users')->where('is_vat_payer', false)->update(['vat_status' => 'non_payer']);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_vat_status_check');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('vat_status');
        });
    }
};
