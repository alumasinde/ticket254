<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $context Extra fields returned inside the JSON error object
     */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        public readonly ?string $errorCode = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
