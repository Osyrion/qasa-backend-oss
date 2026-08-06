<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the core foreign keys that had none.
 *
 * Postgres indexes the referenced side of a foreign key and never the
 * referencing side, so deleting a parent scans the whole child table to prove
 * nothing points at it. quotes.client_id is the one that bit: invoices.client_id
 * has had an index since it was created and its sibling never got one, so
 * deleting a client scanned every quote on the instance.
 *
 * See tests/Feature/Shared/ForeignKeyIndexTest.php, which now fails the build
 * if a new foreign key ships without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->index('client_id');
        });

        Schema::table('recurring_invoice_templates', function (Blueprint $table): void {
            $table->index('client_id');
        });

        Schema::table('tax_filings', function (Blueprint $table): void {
            $table->index('supersedes_id');
        });

        Schema::table('cash_documents', function (Blueprint $table): void {
            $table->index('expense_id');
            $table->index('invoice_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_documents', function (Blueprint $table): void {
            $table->dropIndex(['invoice_payment_id']);
            $table->dropIndex(['expense_id']);
        });

        Schema::table('tax_filings', function (Blueprint $table): void {
            $table->dropIndex(['supersedes_id']);
        });

        Schema::table('recurring_invoice_templates', function (Blueprint $table): void {
            $table->dropIndex(['client_id']);
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropIndex(['client_id']);
        });
    }
};
