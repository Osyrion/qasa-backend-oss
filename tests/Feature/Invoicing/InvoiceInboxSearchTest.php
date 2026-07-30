<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;

/**
 * @return list<string>
 */
function inboxSearchIds(string $term): array
{
    /** @var list<string> */
    return test()->getJson('/api/v1/invoice-inbox?search='.urlencode($term))
        ->assertOk()
        ->json('data.*.id');
}

it('finds an inbox item by words in the recognised document text', function (string $term): void {
    $user = createUser();

    $match = InvoiceInboxItem::factory()->create([
        'user_id' => $user->id,
        'original_filename' => 'scan-001.pdf',
        'ocr_text' => 'Dodávateľ: Stavebniny Novák, Rekonštrukcia strechy, splatnosť 14 dní',
    ]);
    InvoiceInboxItem::factory()->create([
        'user_id' => $user->id,
        'original_filename' => 'scan-002.pdf',
        'ocr_text' => 'Dodávateľ: Kancelárske potreby, papier a tonery',
    ]);

    $this->actingAs($user);

    expect(inboxSearchIds($term))->toBe([$match->id]);
})->with([
    'unaccented' => 'rekonstrukcia',
    'with diacritics' => 'Rekonštrukcia',
    'inflected, via prefix' => 'rekonstrukci',
    'supplier name' => 'novak',
    'two words' => 'stavebniny novak',
]);

it('finds an inbox item by its original filename', function (): void {
    $user = createUser();

    $match = InvoiceInboxItem::factory()->create([
        'user_id' => $user->id,
        'original_filename' => 'faktúra-marec.pdf',
        'ocr_text' => null,
    ]);
    InvoiceInboxItem::factory()->create([
        'user_id' => $user->id,
        'original_filename' => 'zmluva.pdf',
        'ocr_text' => null,
    ]);

    $this->actingAs($user);

    // The filename stays on LIKE, so a mid-word substring still matches —
    // which full text alone would not do.
    expect(inboxSearchIds('ktura-mar'))->toBe([$match->id]);
});

it('does not return another account items', function (): void {
    $user = createUser();
    $other = createUser();

    InvoiceInboxItem::factory()->create([
        'user_id' => $other->id,
        'ocr_text' => 'Rekonštrukcia strechy',
    ]);

    $this->actingAs($user);

    expect(inboxSearchIds('rekonstrukcia'))->toBe([]);
});
