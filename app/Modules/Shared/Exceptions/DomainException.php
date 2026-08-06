<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

use Exception;

class DomainException extends Exception
{
    /**
     * Create a new domain exception.
     */
    public static function because(string $message): self
    {
        return new self($message);
    }

    /**
     * Create a domain exception for validation errors.
     */
    public static function validation(string $message): self
    {
        return new self($message);
    }

    /**
     * Create a domain exception for invalid state.
     */
    public static function invalidState(string $message): self
    {
        return new self($message);
    }

    /**
     * Get the exception as an array for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => 'DomainException',
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
        ];
    }
}
