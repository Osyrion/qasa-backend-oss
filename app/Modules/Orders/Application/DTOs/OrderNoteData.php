<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class OrderNoteData extends Data
{
    public function __construct(
        public readonly string $content,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            content: $request->string('content')->toString(),
        );
    }
}
