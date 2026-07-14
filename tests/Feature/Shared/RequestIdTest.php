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
