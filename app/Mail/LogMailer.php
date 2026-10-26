<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

final class LogMailer implements Mailer
{
    public function __construct(private readonly string $path)
    {
    }

    public function send(EmailMessage $message): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The mail log directory could not be created.');
        }

        $entry = sprintf(
            "[%s] to=%s name=%s subject=%s\n%s\n---\n",
            gmdate('Y-m-d H:i:s'),
            $message->to,
            $message->name ?? '',
            $message->subject,
            $message->text,
        );

        if (file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('The email could not be written to the mail log.');
        }
    }
}
