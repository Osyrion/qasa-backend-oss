<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('attachment_disk')->nullable()->after('note');
            $table->string('attachment_path')->nullable()->after('attachment_disk');
            $table->string('attachment_filename')->nullable()->after('attachment_path');
            $table->string('attachment_mime_type')->nullable()->after('attachment_filename');
            $table->unsignedBigInteger('attachment_size_bytes')->nullable()->after('attachment_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn([
                'attachment_disk',
                'attachment_path',
                'attachment_filename',
                'attachment_mime_type',
                'attachment_size_bytes',
            ]);
        });
    }
};
