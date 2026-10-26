<?php

declare(strict_types=1);

namespace App\Mail;

interface NotificationChannel
{
    public function send(Notification $notification): DeliveryResult;
}
