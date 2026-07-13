<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            // nullOnDelete — deleting the order unlinks the event, it does
            // not delete it; client is reachable transitively via order.client,
            // no separate client_id column (would be a second source of truth).
            $table->foreignUuid('order_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            $table->index(['user_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
