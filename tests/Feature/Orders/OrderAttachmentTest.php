<?php

declare(strict_types=1);

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('uploads an attachment and stores the file', function (): void {
    Storage::fake(config('filesystems.default'));

    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/orders/{$order->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('zapis.txt', 'Zápis zo stretnutia s klientom.'),
            'label' => 'Zápis',
        ])
        ->assertCreated()
        ->assertJsonPath('label', 'Zápis');

    $attachment = OrderAttachment::query()->firstOrFail();
    expect($attachment->mime_type)->toBe('text/plain');
    Storage::disk($attachment->disk)->assertExists((string) $attachment->path);
});

it('rejects a disallowed file type', function (): void {
    Storage::fake(config('filesystems.default'));

    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/orders/{$order->id}/attachments", [
            'file' => UploadedFile::fake()->create('payload.bin', 10),
        ])
        ->assertStatus(422);

    expect(OrderAttachment::query()->count())->toBe(0);
});

it('deletes an attachment together with its stored file', function (): void {
    Storage::fake(config('filesystems.default'));

    $user = createUser();
    $order = Order::factory()->active()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/orders/{$order->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('zapis.txt', 'Zápis zo stretnutia s klientom.'),
        ])
        ->assertCreated();

    $attachment = OrderAttachment::query()->firstOrFail();

    $this->actingAs($user)
        ->deleteJson("/api/v1/orders/{$order->id}/attachments/{$attachment->id}")
        ->assertNoContent();

    Storage::disk($attachment->disk)->assertMissing((string) $attachment->path);
    $this->assertDatabaseMissing('order_attachments', ['id' => $attachment->id]);
});
