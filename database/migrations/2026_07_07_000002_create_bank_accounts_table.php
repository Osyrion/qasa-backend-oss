<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100)->comment('User-facing name, e.g. "Fio EUR"');
            $table->string('bank_name', 100)->nullable();
            $table->string('account_number', 30)->nullable()->comment('Local format, e.g. 123456789/0100');
            $table->string('iban', 34)->nullable()->comment('Required for payment QR');
            $table->string('bic', 11)->nullable();
            $table->enum('currency', ['CZK', 'EUR', 'USD']);
            $table->boolean('is_default')->default(false)->comment('Default account for its currency');
            $table->timestamps();

            $table->index(['user_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
