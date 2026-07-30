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
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)
                ->comment('Fallback account across currencies when no account exists in the invoice currency');
        });

        DB::statement('CREATE UNIQUE INDEX bank_accounts_one_primary_per_user ON bank_accounts (user_id) WHERE is_primary');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bank_accounts_one_primary_per_user');

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
