<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->text('api_key')->comment('Encrypted');
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error')->nullable()->comment('Last 401/403 reason — never the key itself');
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credentials');
    }
};
