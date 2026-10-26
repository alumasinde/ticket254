<?php

declare(strict_types=1);

namespace App\Mail;

use Throwable;

final class EmailChannel implements NotificationChannel
{
    public function __construct(
        private readonly MailTemplates $templates,
        private readonly Mailer $mailer,
    ) {
    }

    public function send(Notification $notification): DeliveryResult
    {
        if (filter_var($notification->recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            return DeliveryResult::failed('The recipient email address is missing or invalid.');
        }

        try {
            $this->mailer->send($this->templates->render($notification));

            return DeliveryResult::sent();
        } catch (Throwable $exception) {
            return DeliveryResult::failed(mb_substr($exception->getMessage(), 0, 500));
        }
    }
}
