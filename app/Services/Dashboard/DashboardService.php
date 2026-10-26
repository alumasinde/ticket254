<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Repositories\DashboardRepository;
use App\Services\Tenants\TenantContext;
use App\Services\Tickets\TicketPresenter;
use App\Support\LocalTime;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class DashboardService
{
    public function __construct(private readonly DashboardRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function data(TenantContext $ctx, array $query = []): array
    {
        $period = $this->period($query, $ctx->timezone);
        $counts = $this->repository->counts($ctx, $period['today_start'], $period['today_end']);
        $resolvedToday = $counts['resolved_today'];
        unset($counts['resolved_today']);

        return [
            'period' => [
                'key' => $period['key'],
                'from' => $this->iso($period['from']),
                'to' => $this->iso($period['to']),
            ],
            'counts' => $counts,
            'resolved_today' => $resolvedToday,
            'avg_resolution_hours' => $this->repository->averageResolutionHours(
                $ctx,
                $period['from'],
                $period['to'],
            ),
            'needs_attention' => array_map(
                fn (array $row): array => TicketPresenter::summary($row) + [
                    'reasons' => $this->reasons($row),
                ],
                $this->repository->needsAttention($ctx),
            ),
            'recent' => array_map(TicketPresenter::summary(...), $this->repository->recent($ctx)),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{key: string, from: string, to: string, today_start: string, today_end: string}
     */
    private function period(array $query, string $timezone): array
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Exception) {
            $zone = new DateTimeZone('UTC');
        }

        $today = new DateTimeImmutable('today', $zone);
        $now = new DateTimeImmutable('now', $zone);
        $fromInput = isset($query['from']) ? (string) $query['from'] : '';
        $toInput = isset($query['to']) ? (string) $query['to'] : '';
        $key = (string) ($query['period'] ?? '7d');

        if ($fromInput !== '' || $toInput !== '') {
            $from = LocalTime::filterBoundary($fromInput === '' ? $today->format('Y-m-d') : $fromInput, $timezone);
            $to = LocalTime::filterBoundary($toInput === '' ? $now->format('Y-m-d') : $toInput, $timezone, true);
            $key = 'custom';
        } else {
            $days = match ($key) {
                'today' => 0,
                '30d' => 30,
                default => 7,
            };

            if ($key === 'today') {
                $from = $today->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $to = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $key = 'today';
            } else {
                $start = $today->modify('-' . $days . ' days');
                $from = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $to = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $key = $days === 30 ? '30d' : '7d';
            }
        }

        $from = $from ?? $today->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to = $to ?? $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        if (strcmp($from, $to) > 0) {
            [$from, $to] = [$to, $from];
        }

        $tomorrow = $today->modify('+1 day');

        return [
            'key' => $key,
            'from' => $from,
            'to' => $to,
            'today_start' => $today->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'today_end' => $tomorrow->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function reasons(array $row): array
    {
        $reasons = [];
        $due = $row['due_at'] ?? null;

        if ($due !== null && (int) ($row['status_terminal'] ?? 0) === 0) {
            $timestamp = strtotime((string) $due . ' UTC');

            if ($timestamp !== false && $timestamp < time()) {
                $reasons[] = 'overdue';
            }
        }

        if (($row['assigned_user_id'] ?? null) === null) {
            $reasons[] = 'unassigned';
        }

        if (($row['first_response_at'] ?? null) === null) {
            $created = strtotime((string) ($row['created_at'] ?? '') . ' UTC');

            if ($created !== false && $created < time() - 4 * 3600) {
                $reasons[] = 'no_first_response';
            }
        }

        return $reasons;
    }

    private function iso(string $utc): string
    {
        $timestamp = strtotime($utc . ' UTC');

        return $timestamp === false ? $utc : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
