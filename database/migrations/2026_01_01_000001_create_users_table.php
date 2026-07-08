<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->string('name');
            $table->string('surname');
            $table->string('email')->unique();
            $table->string('phone', 30)->nullable();
            $table->string('password')->nullable()->comment('Null if Google auth only');
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar_path')->nullable();
            $table->string('color', 7)->nullable()->comment('Hex, e.g. #3B82F6');
            $table->string('ico', 20)->nullable();
            $table->string('dic', 20)->nullable();
            $table->boolean('is_vat_payer')->default(false);
            $table->tinyInteger('tax_flat_rate')->unsigned()->default(0)->comment('0-80; 0 = real expenses');
            $table->enum('default_currency', ['CZK', 'EUR', 'USD'])->default('EUR');
            $table->string('invoice_prefix', 10)->default('FA');
            $table->string('locale', 5)->default('sk')->comment('UI language');
            $table->string('country', 2)->default('SK')->comment('ISO 3166-1 alpha-2');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 10)->nullable();

            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
