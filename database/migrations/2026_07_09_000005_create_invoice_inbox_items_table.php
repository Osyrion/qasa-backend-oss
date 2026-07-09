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
        Schema::create('invoice_inbox_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->nullOnDelete()->comment('Set once converted');

            $table->string('status', 20)->default('pending');

            $table->string('disk', 20)->default('local');
            $table->string('path')->comment('Stored copy, e.g. supplier-invoices/inbox/{account_id}/{uuid}.pdf');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('file_hash', 64)->comment('SHA-256, dedup across re-scans');

            $table->text('ocr_text')->nullable()->comment('Raw extracted text');
            $table->string('ocr_engine', 20)->nullable()->comment('pdfparser|tesseract');
            $table->json('suggestions')->nullable()->comment('Parsed field suggestions for prefilling the review form');
            $table->foreignUuid('matched_client_id')->nullable()->constrained('clients')->nullOnDelete()->comment('Auto-matched vendor by ICO');

            $table->timestamp('scanned_at');
            $table->text('error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->unique(['user_id', 'file_hash']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE invoice_inbox_items ADD CONSTRAINT invoice_inbox_items_status_check CHECK (status::text IN ('pending', 'imported', 'ignored', 'failed'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_inbox_items');
    }
};
