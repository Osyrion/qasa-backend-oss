<?php

declare(strict_types=1);

use App\Modules\Shared\Support\TenantPolicy;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        TenantPolicy::own('tax_filings');
    }

    public function down(): void
    {
        TenantPolicy::drop('tax_filings');
    }
};
