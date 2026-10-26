<?php

declare(strict_types=1);

namespace App\Mail;

final class ArrayMailer implements Mailer
{
    /** @var list<EmailMessage> */
    public array $messages = [];

    public function send(EmailMessage $message): void
    {
        $this->messages[] = $message;
    }
}
