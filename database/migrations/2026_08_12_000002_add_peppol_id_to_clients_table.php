<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's Peppol participant id, e.g. "0245:12345678" — scheme plus
 * identifier, as the network addresses it.
 *
 * Master data about a business partner, alongside ico/dic/vat_id/bank_iban,
 * so it belongs in this **core** table even though only the premium transport
 * acts on it. Same arrangement as external_source/external_id: the column
 * ships in both editions and an OSS client row keeps the same shape, while
 * the module that writes and uses it is premium.
 *
 * Not derived from vat_id at read time on purpose. The scheme prefix is
 * assigned during SMP registration by the access point and is not ours to
 * guess; guessing it would produce an address that routes to nobody, and the
 * failure would show up as an invoice that silently never arrived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('peppol_id')->nullable()->after('vat_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('peppol_id');
        });
    }
};
