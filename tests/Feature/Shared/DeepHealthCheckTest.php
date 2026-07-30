<?php

declare(strict_types=1);

it('reports healthy when every check passes', function (): void {
    $user = createUser();

    $response = $this->actingAs($user)->getJson('/up/deep');

    $response->assertOk()
        ->assertJsonPath('db.status', 'ok')
        ->assertJsonPath('queue.status', 'ok')
        ->assertJsonPath('mail.status', 'ok')
        ->assertJsonPath('storage.status', 'ok');

    expect($response->json('queue.size'))->toBeInt()
        ->and($response->json('queue.oldest_pending_s'))->toBeInt();
});

it('rejects unauthenticated access', function (): void {
    $this->getJson('/up/deep')->assertUnauthorized();
});
