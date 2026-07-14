<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTOs;

use App\Modules\Shared\Authorization\AbilityCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class CreateTokenData extends Data
{
    /**
     * @param  list<string>  $abilities
     */
    public function __construct(
        public readonly string $name,
        public readonly array $abilities,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(AbilityCatalog::abilities())],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        /** @var list<string> $abilities */
        $abilities = $request->input('abilities', []);

        return new self(
            name: $request->string('name')->toString(),
            abilities: $abilities,
        );
    }
}
