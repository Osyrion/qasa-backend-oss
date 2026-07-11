<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('color', 9)->nullable()->comment('#RRGGBB');
            $table->boolean('is_all_day')->default(false);

            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->comment('Exclusive; midnight stored as next-day 00:00');

            $table->string('source', 32)->default('manual')->comment('manual|csv_import|ics_import; SaaS adds sync sources');
            $table->string('external_uid')->nullable()->comment('ICS UID / import hash for dedupe & future external sync');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'starts_at']);
            $table->index(['user_id', 'ends_at']);
            $table->unique(['user_id', 'source', 'external_uid'], 'unique_external_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
