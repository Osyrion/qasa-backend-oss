<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('color', 7)->nullable()->comment('Hex color');
            $table->longText('readme')->nullable()->comment('Markdown — brief, description, scope');
            $table->enum('status', ['active', 'paused', 'completed', 'archived'])->default('active');

            // Billing — how this order is charged overall
            // mixed = ad-hoc items only (e.g. repair shop), no default rate
            $table->enum('billing_type', [
                'hourly',
                'daily',
                'monthly',
                'fixed_per_item',
                'mixed',
            ])->default('mixed');

            // Default rate per billing unit — null for mixed
            $table->decimal('rate', 10, 2)->nullable()->comment('Default rate per billing unit');
            $table->enum('currency', ['CZK', 'EUR', 'USD'])->nullable()->comment('Overrides client currency');

            // Estimation
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->decimal('estimated_price', 10, 2)->nullable()->comment('Excl. VAT');

            $table->date('deadline')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['client_id', 'status']);
        });

        Schema::create('order_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('order_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // Storage backend — extensible for future integrations
            $table->enum('disk', ['local', 'r2', 'sharepoint', 'onedrive'])->default('local');

            // Local / R2 storage
            $table->string('path')->nullable()->comment('Relative path for local/r2');

            // External providers (SharePoint, OneDrive via MS Graph API)
            $table->string('external_id')->nullable()->comment('Document ID from external provider');
            $table->string('external_url')->nullable()->comment('Direct webUrl from provider');

            $table->string('filename')->comment('Original filename for display');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('label')->nullable()->comment('Optional label, e.g. Zmluva o dielo');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_attachments');
        Schema::dropIfExists('order_notes');
        Schema::dropIfExists('orders');
    }
};
