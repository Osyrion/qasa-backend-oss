<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_inbox_items', function (Blueprint $table): void {
            $table->string('suggestions_provider')->nullable()->after('suggestions_source')
                ->comment('anthropic|… — which provider produced "suggestions" when suggestions_source is ai/ai_byok');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_inbox_items', function (Blueprint $table): void {
            $table->dropColumn('suggestions_provider');
        });
    }
};
