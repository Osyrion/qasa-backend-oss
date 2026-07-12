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
        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();

            $table->string('quote_number', 30);
            $table->string('status', 20)->default('draft');
            $table->date('issued_at');
            $table->date('valid_until')->nullable();

            $table->enum('currency', ['CZK', 'EUR', 'USD'])->default('EUR');
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->text('note')->nullable();
            $table->text('note_above')->nullable();

            $table->json('supplier_snapshot')->nullable()->comment('Frozen at first draft -> sent transition');
            $table->json('client_snapshot')->nullable()->comment('Frozen at first draft -> sent transition');

            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamp('public_first_viewed_at')->nullable();
            $table->unsignedInteger('public_view_count')->default(0);

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('decision_ip', 45)->nullable();

            $table->timestamp('emailed_at')->nullable();
            $table->string('emailed_to')->nullable();

            $table->foreignUuid('converted_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('converted_order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'quote_number']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'issued_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE quotes ADD CONSTRAINT quotes_status_check CHECK (status::text IN ('draft', 'sent', 'accepted', 'rejected', 'expired'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
