<?php

use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number', 30)->nullable()->comment('e.g. FA-2024-001; null until issued')->change();
        });
    }

    public function down(): void
    {
        // Grandfather-free rollback: any draft left without a number is
        // assigned one, scoped per account, before the column is locked
        // back to NOT NULL — mirrors EloquentInvoiceRepository::nextInvoiceNumber().
        Invoice::withoutGlobalScope('user')
            ->withTrashed()
            ->whereNull('invoice_number')
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->each(function (Invoice $invoice): void {
                $next = (int) Invoice::withoutGlobalScope('user')
                    ->withTrashed()
                    ->where('user_id', $invoice->user_id)
                    ->whereNotNull('invoice_number')
                    ->count() + 1;

                $invoice->update([
                    'invoice_number' => sprintf('FA-%s-%03d', now()->format('Y'), $next),
                ]);
            });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number', 30)->nullable(false)->comment('e.g. FA-2024-001')->change();
        });
    }
};
