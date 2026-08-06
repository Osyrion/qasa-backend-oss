<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable override for SkRateTable/CzRateTable (Sk|CzRates2025/2026)
 * — one table per country, mirroring the existing namespace split
 * (Infrastructure/{Sk,Cz}/Rates), since the two interfaces share almost no
 * fields. `year` is the primary key: a row is a full replacement for one
 * year's hardcoded class, not a patch — DbBackedSkRateTable/
 * DbBackedCzRateTable read a row whole or fall back to the hardcoded class
 * entirely, never merge the two.
 *
 * Not tenant data — no user_id, no RLS policy. These are global parameters
 * an admin maintains for everyone, same category as SubscriptionPlan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sk_tax_rate_parameters', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->decimal('flat_expense_rate', 10, 4);
            $table->decimal('flat_expense_cap', 12, 2);
            $table->decimal('low_rate_income_threshold', 12, 2);
            $table->decimal('low_tax_rate', 10, 4);
            $table->decimal('standard_tax_rate', 10, 4);
            $table->decimal('high_tax_rate', 10, 4);
            $table->decimal('high_rate_threshold', 12, 2);
            $table->decimal('life_minimum', 12, 2);
            $table->decimal('social_contribution_threshold', 12, 2);
            $table->decimal('social_contribution_rate', 10, 4);
            $table->decimal('health_contribution_rate', 10, 4);
            $table->decimal('assessment_base_share', 10, 4);
            $table->decimal('child_bonus_under_15', 12, 2);
            $table->decimal('child_bonus_15_to_18', 12, 2);
            $table->json('child_bonus_cap_shares')->comment('§33 ods. 6 bands, keyed by eligible child count 1-6, e.g. {"1":0.20,...,"6":0.55}');
            $table->timestamps();
        });

        Schema::create('cz_tax_rate_parameters', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->decimal('low_tax_rate', 10, 4);
            $table->decimal('high_tax_rate', 10, 4);
            $table->decimal('high_rate_threshold', 12, 2);
            $table->decimal('basic_taxpayer_credit', 12, 2);
            $table->decimal('spouse_credit', 12, 2);
            $table->json('child_credits')->comment('Daňové zvýhodnění na dítě, keyed by birth order 1-3, e.g. {"1":15204,"2":22320,"3":27840}');
            $table->json('flat_rate_income_caps')->comment('Paušální výdaje, keyed by expense percent, e.g. {"80":1000000,"60":1500000,"40":2000000,"30":2000000}');
            $table->decimal('social_contribution_rate', 10, 4);
            $table->decimal('health_contribution_rate', 10, 4);
            $table->decimal('assessment_base_share', 10, 4);
            $table->decimal('min_monthly_social_base_main_activity', 12, 2);
            $table->decimal('min_monthly_health_base_main_activity', 12, 2);
            $table->decimal('secondary_activity_threshold', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cz_tax_rate_parameters');
        Schema::dropIfExists('sk_tax_rate_parameters');
    }
};
