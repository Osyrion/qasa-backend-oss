<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Contracts\ProcessInboxFileActionInterface;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/UblFixtures.php';

/**
 * A received e-invoice joins the existing inbox flow rather than getting one
 * of its own — the reviewer confirms exact values instead of correcting
 * guessed ones, which is the whole point of accepting UBL on the way in.
 */
function ingestUbl(User $owner, string $xml, string $filename = 'invoice.xml'): InvoiceInboxItem
{
    Storage::fake('local');
    Storage::disk('local')->put("incoming/{$filename}", $xml);

    $item = app(ProcessInboxFileActionInterface::class)->execute($owner, 'local', "incoming/{$filename}");

    // Null only means "duplicate hash", which no test here produces —
    // asserting it up front keeps every caller free of null handling.
    expect($item)->not->toBeNull();
    assert($item !== null);

    return $item;
}

it('settles an incoming UBL invoice without running OCR at all', function (): void {
    $owner = createUser();

    $item = ingestUbl($owner, minimalUbl());
    $suggestions = $item->suggestions ?? [];

    expect($item->status)->toBe(InvoiceInboxStatus::Pending->value)
        ->and($item->suggestions_source)->toBe('ubl')
        // Nothing was OCR'd, so claiming an engine or storing extracted text
        // would misrepresent where the numbers came from.
        ->and($item->ocr_engine)->toBeNull()
        ->and($item->ocr_text)->toBeNull()
        ->and($suggestions['supplier_invoice_number'] ?? null)->toBe('DODA-1')
        // toEqual, not toBe: the suggestions round-trip through jsonb, and
        // 246.0 comes back as an int.
        ->and($suggestions['total'] ?? null)->toEqual(246.0)
        ->and($suggestions['variable_symbol'] ?? null)->toBe('7788')
        ->and($suggestions['iban'] ?? null)->toBe('SK3112000000198742637541')
        ->and($suggestions['vat_breakdown'][0]['rate'] ?? null)->toEqual(23.0);
});

it('matches the vendor by the registration id stated in the document', function (): void {
    $owner = createUser();
    $vendor = Client::factory()->create([
        'user_id' => $owner->id,
        'is_vendor' => true,
        'ico' => '99887766',
    ]);

    $item = ingestUbl($owner, minimalUbl(ico: '99887766'));

    expect($item->matched_client_id)->toBe($vendor->id);
});

it('still falls back to OCR for an XML attachment that is not an e-invoice', function (): void {
    $owner = createUser();

    // An XML attachment is not automatically a UBL invoice — the inbox has
    // to keep working for whatever else turns up.
    $item = ingestUbl($owner, '<?xml version="1.0"?><somethingElse/>', 'other.xml');

    expect($item->suggestions_source)->not->toBe('ubl')
        // There is no OCR engine for XML, so this settles as failed with a
        // stated reason rather than as an empty pending item the reviewer
        // would have to type in from scratch. Worth pinning now that XML is
        // an accepted upload and not only reachable from a test.
        ->and($item->status)->toBe(InvoiceInboxStatus::Failed->value)
        ->and($item->error)->not->toBeNull();
});

/**
 * The tests above reach ProcessInboxFileAction directly, which is how the
 * UBL path stayed unreachable in production for a while: every real entry
 * point gates on ALLOWED_MIMES first, and XML was not on the list. So each
 * of those doors gets its own test — the parser working proves nothing if
 * no file can get to it.
 */
it('accepts an e-invoice uploaded straight into the inbox', function (): void {
    Storage::fake('local');
    $owner = createUser();

    $file = UploadedFile::fake()->createWithContent('faktura.xml', minimalUbl('UPLOAD-1'));

    $response = $this->actingAs($owner)->postJson('/api/v1/invoice-inbox/upload', ['file' => $file]);

    $response->assertStatus(202);

    $item = InvoiceInboxItem::withoutGlobalScope('user')->firstOrFail();

    expect($item->suggestions_source)->toBe('ubl')
        ->and($item->suggestions['supplier_invoice_number'] ?? null)->toBe('UPLOAD-1')
        // Nothing was OCR'd — the fields were stated, not read off a page.
        ->and($item->ocr_engine)->toBeNull();
});

it('picks an e-invoice up from the watched folder', function (): void {
    Storage::fake('local');
    $owner = createUser(['invoice_inbox_enabled' => true]);

    Storage::disk('local')->put("inbox/{$owner->id}/faktura.xml", minimalUbl('SCAN-1'));

    $this->artisan('qasa:invoices:scan-inbox')->assertSuccessful();

    $item = InvoiceInboxItem::withoutGlobalScope('user')->firstOrFail();

    expect($item->suggestions_source)->toBe('ubl')
        ->and($item->suggestions['supplier_invoice_number'] ?? null)->toBe('SCAN-1');

    Storage::disk('local')->assertExists("inbox/{$owner->id}/processed/faktura.xml");
});
