<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('client_type', ['individual', 'self_employed', 'company']);
            $table->string('title')->nullable();
            $table->string('name')->nullable();
            $table->string('surname')->nullable();
            $table->string('company_name')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('color', 7)->nullable()->comment('Hex color');
            $table->string('ico', 20)->nullable();
            $table->string('dic', 20)->nullable();
            $table->boolean('is_vat_payer')->default(false);
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('country', 2)->default('SK')->comment('ISO 3166-1 alpha-2');
            $table->enum('currency', ['CZK', 'EUR', 'USD'])->default('EUR');
            $table->string('locale', 5)->default('sk')->comment('Invoice language');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'client_type']);
        });

        Schema::create('contact_persons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('name');
            $table->string('surname');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_persons');
        Schema::dropIfExists('clients');
    }
};
