<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->string('code', 10)->comment('e.g. SK-23');
            $table->char('country', 2);
            $table->decimal('rate', 5, 2);
            $table->string('label')->nullable();
            $table->boolean('is_default')->default(false)->comment('Default rate for its user+country');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'code']);
            $table->index(['user_id', 'country']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_rates');
    }
};
