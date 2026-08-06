<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, Part B (docs/plans/SK_VAT_FILING_EDANE_PLAN.md) — an immutable
 * archive of generated filings. Deviates from the plan's literal "xml_path"
 * naming: content is stored as a plain text column (`content`) rather than
 * a file on a Storage disk. These documents are small (a control statement
 * or EU sales list export for a realistic account is at most a few hundred
 * KB) and a DB column keeps the "never regenerated, never rewritten" byte-
 * for-byte guarantee trivially true without adding Storage-disk lifecycle
 * concerns for what is fundamentally a compliance record, not a user file.
 *
 * `type` includes `vat_return` for forward compatibility with Part A (the
 * actual eDane/EPO VAT return XML) even though nothing generates that type
 * yet — deferred pending a verified official XSD, per plan doc's own v1
 * scope note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_filings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->comment('control_statement|eu_sales_list|income_tax|vat_return');
            $table->string('country', 2)->comment('Residency at generation time — SK|CZ');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_quarter')->nullable();
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->text('content');
            $table->char('sha256', 64);
            $table->string('status', 20)->default('generated')->comment('generated|filed|superseded');
            $table->timestamp('filed_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('supersedes_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type', 'period_year', 'period_quarter', 'period_month']);
        });

        // Self-referencing FK: added in a separate ALTER, after the primary
        // key constraint the FK depends on actually exists — Postgres
        // rejects a self-referencing foreign key declared inside the same
        // CREATE TABLE statement it references.
        Schema::table('tax_filings', function (Blueprint $table): void {
            $table->foreign('supersedes_id')->references('id')->on('tax_filings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_filings');
    }
};
