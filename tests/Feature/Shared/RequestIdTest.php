<?php

declare(strict_types=1);

it('generates an X-Request-Id header when the caller sends none', function (): void {
    $response = $this->getJson('/up/deep');

    $response->assertHeader('X-Request-Id');
    expect($response->headers->get('X-Request-Id'))->toBeString()->not->toBeEmpty();
});

it('echoes back a caller-supplied X-Request-Id unchanged', function (): void {
    $response = $this->withHeaders(['X-Request-Id' => 'caller-supplied-id'])
        ->getJson('/up/deep');

    $response->assertHeader('X-Request-Id', 'caller-supplied-id');
});

$uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

it('replaces an X-Request-Id with unsafe characters by a generated uuid', function () use ($uuidPattern): void {
    $response = $this->withHeaders(['X-Request-Id' => 'evil; DROP logs -- <x>'])
        ->getJson('/up/deep');

    expect($response->headers->get('X-Request-Id'))->toMatch($uuidPattern);
});

it('replaces an over-long X-Request-Id by a generated uuid', function () use ($uuidPattern): void {
    $response = $this->withHeaders(['X-Request-Id' => str_repeat('a', 200)])
        ->getJson('/up/deep');

    expect($response->headers->get('X-Request-Id'))->toMatch($uuidPattern);
});
