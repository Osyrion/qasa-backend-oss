<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Nullable: accounts before complete-residency (step 2) don't
            // have it yet; always filled afterward. Sole source of the
            // supplier's printed name — see User::supplierName().
            $table->string('company_name')->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('company_name');
        });
    }
};
