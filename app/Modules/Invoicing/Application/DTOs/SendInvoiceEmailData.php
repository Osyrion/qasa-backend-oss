<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class SendInvoiceEmailData extends Data
{
    /**
     * @param  list<string>|null  $cc
     */
    public function __construct(
        public readonly ?string $to = null,
        public readonly ?array $cc = null,
        public readonly ?string $message = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'to' => ['nullable', 'email', 'max:255'],
            'cc' => ['nullable', 'array', 'max:5'],
            'cc.*' => ['required', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        /** @var array<int, string> $cc */
        $cc = $request->input('cc', []);

        return new self(
            to: $request->filled('to') ? $request->string('to')->toString() : null,
            cc: $cc !== [] ? array_values($cc) : null,
            message: $request->filled('message') ? $request->string('message')->toString() : null,
        );
    }
}
