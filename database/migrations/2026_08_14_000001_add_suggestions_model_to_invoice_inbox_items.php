<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Act art. 50 marking: the provider alone ("anthropic") does not say
 * which model read the document, and the configured model moves on. Existing
 * rows keep a null model — they are marked as AI-produced by
 * suggestions_source as before, just without naming the exact version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_inbox_items', function (Blueprint $table): void {
            $table->string('suggestions_model')->nullable()->after('suggestions_provider')
                ->comment('claude-haiku-4-5|… — which model produced "suggestions" when suggestions_source is ai/ai_byok');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_inbox_items', function (Blueprint $table): void {
            $table->dropColumn('suggestions_model');
        });
    }
};
