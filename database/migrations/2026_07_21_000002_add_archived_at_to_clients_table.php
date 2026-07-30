<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // Not a soft-delete alias — an archived client stays visible in
            // lists (filterable) and its existing documents are untouched;
            // it's simply excluded from limit counters and new documents.
            $table->timestamp('archived_at')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
