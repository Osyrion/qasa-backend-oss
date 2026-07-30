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
     * Create a domain exception with error code.
     */
    public static function withCode(string $message, string $code): self
    {
        $exception = new self($message);
        $exception->code = $code;

        return $exception;
    }

    /**
     * Create a domain exception for resource not found.
     */
    public static function resourceNotFound(string $resource, string $identifier): self
    {
        return new self("{$resource} '{$identifier}' not found.");
    }

    /**
     * Create a domain exception for unauthorized access.
     */
    public static function unauthorized(string $message = 'Unauthorized access.'): self
    {
        return new self($message);
    }

    /**
     * Create a domain exception for forbidden access.
     */
    public static function forbidden(string $message = 'Access forbidden.'): self
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
     * Create a domain exception for business logic violations.
     */
    public static function businessRule(string $message): self
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
     * Create a domain exception for configuration errors.
     */
    public static function configuration(string $message): self
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
