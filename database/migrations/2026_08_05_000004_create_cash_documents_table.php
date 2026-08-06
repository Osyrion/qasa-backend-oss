<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash receipts and payments (PPD/VPD) — the missing middle of the
 * "income → record → tax return" chain for a sole trader on cash-basis
 * bookkeeping.
 *
 * Append-only: a cash document is an accounting record, so there is no
 * update and no delete. A mistake is corrected by issuing the opposite
 * document linked through reverses_cash_document_id — both stay in the book
 * and cancel out, exactly as they would on paper.
 *
 * The two nullable links are what keep the tax return honest: a document
 * that merely papers over an already-recorded invoice payment or expense
 * must not be counted as income or cost a second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('type', 10);
            $table->string('number', 40);
            $table->date('issued_at');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 15, 2)->nullable();
            $table->string('counterparty')->nullable();
            $table->string('description');
            $table->text('note')->nullable();

            // Already-recorded elsewhere: the tax aggregator must skip these.
            $table->foreignUuid('invoice_payment_id')->nullable()
                ->constrained('invoice_payments')->nullOnDelete();
            $table->foreignUuid('expense_id')->nullable()
                ->constrained('expenses')->nullOnDelete();

            // One document, at most one reversal. The foreign key is added
            // separately below: Laravel emits the primary key at the end of
            // CREATE TABLE, so a self-reference declared here has nothing to
            // point at yet.
            $table->uuid('reverses_cash_document_id')->nullable()->unique();

            $table->timestamps();

            $table->unique(['user_id', 'number']);
            $table->index(['user_id', 'issued_at']);
            $table->index(['user_id', 'type', 'issued_at']);
        });

        Schema::table('cash_documents', function (Blueprint $table): void {
            $table->foreign('reverses_cash_document_id')
                ->references('id')->on('cash_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_documents');
    }
};
