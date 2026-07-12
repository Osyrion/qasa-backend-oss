<?php

declare(strict_types=1);

use App\Modules\TimeTracking\Domain\Models\Expense;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('uploads an attachment to an expense', function (): void {
    $user = createUser();
    $expense = Expense::factory()->create(['user_id' => $user->id]);

    $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

    $response = $this->actingAs($user)
        ->postJson("/api/v1/expenses/{$expense->id}/attachment", ['file' => $file])
        ->assertOk();

    expect($response->json('attachment.filename'))->toBe('receipt.pdf')
        ->and($response->json('attachment.mime_type'))->toBe('application/pdf');

    Storage::disk('local')->assertExists((string) $expense->refresh()->attachment_path);
});

it('rejects a disallowed mime type', function (): void {
    $user = createUser();
    $expense = Expense::factory()->create(['user_id' => $user->id]);

    $file = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');

    $this->actingAs($user)
        ->postJson("/api/v1/expenses/{$expense->id}/attachment", ['file' => $file])
        ->assertUnprocessable();

    expect($expense->refresh()->hasAttachment())->toBeFalse();
});

it('returns 404 uploading to another account\'s expense', function (): void {
    $owner = createUser();
    $expense = Expense::factory()->create(['user_id' => $owner->id]);

    $other = createUser();
    $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

    $this->actingAs($other)
        ->postJson("/api/v1/expenses/{$expense->id}/attachment", ['file' => $file])
        ->assertNotFound();
});

it('replaces an existing attachment and removes the old file', function (): void {
    $user = createUser();
    $expense = Expense::factory()->create(['user_id' => $user->id]);

    $first = UploadedFile::fake()->create('receipt-1.pdf', 100, 'application/pdf');
    $this->actingAs($user)->postJson("/api/v1/expenses/{$expense->id}/attachment", ['file' => $first])->assertOk();
    $firstPath = (string) $expense->refresh()->attachment_path;

    $second = UploadedFile::fake()->create('receipt-2.pdf', 100, 'application/pdf');
    $response = $this->actingAs($user)
        ->postJson("/api/v1/expenses/{$expense->id}/attachment", ['file' => $second])
        ->assertOk();

    expect($response->json('attachment.filename'))->toBe('receipt-2.pdf');

    Storage::disk('local')->assertMissing($firstPath);
    Storage::disk('local')->assertExists((string) $expense->refresh()->attachment_path);
});

it('downloads the attachment', function (): void {
    $user = createUser();
    $expense = Expense::factory()->create(['user_id' => $user->id]);

    $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');
    $this->actingAs($user)->postJson("/api/v1/expenses/{$expense->id}/attachment", ['file' => $file])->assertOk();

    $this->actingAs($user)
        ->get("/api/v1/expenses/{$expense->id}/attachment")
        ->assertOk();
});

it('returns 404 downloading when there is no attachment', function (): void {
    $user = createUser();
    $expense = Expense::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/expenses/{$expense->id}/attachment")
        ->assertNotFound()
        ->assertJson(['message' => __('time_tracking.attachment_missing')]);
});

it('deletes the attachment', function (): void {
    $user = createUser();
    $expense = Expense::factory()->create(['user_id' => $user->id]);

    $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');
    $this->actingAs($user)->postJson("/api/v1/expenses/{$expense->id}/attachment", ['file' => $file])->assertOk();
    $path = (string) $expense->refresh()->attachment_path;

    $this->actingAs($user)
        ->deleteJson("/api/v1/expenses/{$expense->id}/attachment")
        ->assertNoContent();

    Storage::disk('local')->assertMissing($path);
    expect($expense->refresh()->hasAttachment())->toBeFalse();
});
