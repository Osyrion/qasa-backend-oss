<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTOs;

use Spatie\LaravelData\Data;

class ConfirmPhoneVerificationData extends Data
{
    public function __construct(
        public readonly string $code,
    ) {}

    /**
     * The number is not resent here: the code was issued *for* a number and
     * the row remembers which. Taking it from the request again would let a
     * caller spend a code on a different number than the one it was sent to.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
