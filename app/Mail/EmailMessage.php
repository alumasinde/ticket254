<?php

declare(strict_types=1);

namespace App\Mail;

final class EmailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly ?string $name,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
    ) {
    }
}
