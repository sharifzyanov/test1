<?php

declare(strict_types=1);

namespace Warehouse;

use RuntimeException;

/**
 * Simple exception type for communicating validation errors to the API layer.
 */
class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private array $errors)
    {
        parent::__construct('Validation failed');
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
