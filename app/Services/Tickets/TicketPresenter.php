<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Support\TicketReference;

final class TicketPresenter
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function summary(array $row): array
    {
        return [
            'reference' => TicketReference::format((int) $row['ticket_number']),
            'subject' => (string) $row['subject'],
            'status' => [
                'code' => (string) $row['status_code'],
                'label' => (string) $row['status_label'],
            ],
            'priority' => [
                'code' => (string) $row['priority_code'],
                'label' => (string) $row['priority_label'],
            ],
            'requester' => [
                'name' => (string) $row['requester_name'],
                'email' => (string) $row['requester_email'],
            ],
            'team' => self::named($row, 'team_id', 'team_name'),
            'assigned_user' => self::assignee($row),
            'category' => self::named($row, 'category_id', 'category_name'),
            'due_at' => self::iso($row['due_at'] ?? null),
            'is_overdue' => self::isOverdue($row),
            'created_at' => self::iso($row['created_at'] ?? null),
            'updated_at' => self::iso($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public static function detail(array $row, array $events): array
    {
        $data = self::summary($row);

        return [
            'reference' => $data['reference'],
            'version' => (int) $row['version'],
            'subject' => $data['subject'],
            'description' => (string) $row['description'],
            'status' => $data['status'],
            'priority' => $data['priority'],
            'requester' => [
                'name' => $data['requester']['name'],
                'email' => $data['requester']['email'],
                'phone' => $row['requester_phone'] === null ? null : (string) $row['requester_phone'],
            ],
            'team' => $data['team'],
            'assigned_user' => $data['assigned_user'],
            'category' => $data['category'],
            'site' => self::named($row, 'site_id', 'site_name'),
            'due_at' => $data['due_at'],
            'resolved_at' => self::iso($row['resolved_at'] ?? null),
            'closed_at' => self::iso($row['closed_at'] ?? null),
            'is_overdue' => $data['is_overdue'],
            'created_at' => $data['created_at'],
            'updated_at' => $data['updated_at'],
            'events' => array_map(self::event(...), $events),
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function event(array $event): array
    {
        $oldValue = self::jsonValue($event['old_value'] ?? null);
        $newValue = self::jsonValue($event['new_value'] ?? null);

        return [
            'id' => (int) $event['id'],
            'event_type' => (string) $event['event_type'],
            'summary' => self::eventSummary((string) $event['event_type'], $newValue),
            'actor' => $event['actor_user_id'] === null ? null : [
                'id' => (int) $event['actor_user_id'],
                'name' => trim((string) $event['actor_first_name'] . ' ' . (string) $event['actor_last_name']),
            ],
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'created_at' => self::iso($event['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string}|null
     */
    private static function named(array $row, string $idKey, string $nameKey): ?array
    {
        if (($row[$idKey] ?? null) === null || ($row[$nameKey] ?? null) === null) {
            return null;
        }

        return [
            'id' => (int) $row[$idKey],
            'name' => (string) $row[$nameKey],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string}|null
     */
    private static function assignee(array $row): ?array
    {
        if (($row['assigned_user_id'] ?? null) === null) {
            return null;
        }

        return [
            'id' => (int) $row['assigned_user_id'],
            'name' => trim((string) $row['assignee_first_name'] . ' ' . (string) $row['assignee_last_name']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isOverdue(array $row): bool
    {
        if (($row['due_at'] ?? null) === null || (int) ($row['status_terminal'] ?? 0) === 1) {
            return false;
        }

        $due = strtotime((string) $row['due_at'] . ' UTC');

        return $due !== false && $due < time();
    }

    /**
     * @param array<string, mixed>|null $value
     */
    private static function eventSummary(string $type, ?array $value): string
    {
        $summary = match ($type) {
            'created' => 'Ticket created',
            'edited' => 'Details edited',
            default => ucfirst(str_replace('_', ' ', $type)),
        };

        $changed = $value['changed'] ?? null;

        if ($type === 'edited' && is_array($changed) && $changed !== []) {
            $summary .= ': ' . implode(', ', array_map(
                static fn (mixed $field): string => str_replace('_', ' ', (string) $field),
                $changed,
            ));
        }

        return $summary;
    }

    private static function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function jsonValue(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
