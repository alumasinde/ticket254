<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class LocalTime
{
    /**
     * Convert a filter value to a UTC database timestamp.
     * A date without a time is expanded to the start or end of that day in the workspace timezone.
     */
    public static function filterBoundary(?string $value, string $timezone, bool $endOfDay = false): ?string
    {
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            return null;
        }

        try {
            $zone = new DateTimeZone($timezone);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $value .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
            }

            $date = new DateTimeImmutable($value, $zone);

            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception) {
            return null;
        }
    }

    public static function inputToUtc(?string $value, string $timezone): ?string
    {
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone($timezone));

            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception) {
            return null;
        }
    }
}
