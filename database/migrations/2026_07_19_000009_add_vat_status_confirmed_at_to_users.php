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
            // users.vat_status has a DB default, so it alone can't distinguish
            // "user confirmed their VAT status" from "never touched it" —
            // this timestamp is set explicitly by UpdateProfileAction instead,
            // solely to drive the onboarding setup checklist.
            $table->timestamp('vat_status_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('vat_status_confirmed_at');
        });
    }
};
