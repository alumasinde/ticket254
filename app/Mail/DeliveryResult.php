<?php

declare(strict_types=1);

namespace App\Mail;

final class DeliveryResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
    ) {
    }

    public static function sent(): self
    {
        return new self(true);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }
}
