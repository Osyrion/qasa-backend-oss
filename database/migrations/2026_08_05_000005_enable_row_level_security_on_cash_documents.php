<?php

declare(strict_types=1);

use App\Modules\Shared\Support\TenantPolicy;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        TenantPolicy::own('cash_documents');
    }

    public function down(): void
    {
        TenantPolicy::drop('cash_documents');
    }
};
