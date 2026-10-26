<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class InvalidTransitionException extends HttpException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(string $from, string $to, array $allowed)
    {
        parent::__construct(
            409,
            "Cannot move from {$from} to {$to}.",
            [],
            'invalid_transition',
            ['allowed' => $allowed],
        );
    }
}
