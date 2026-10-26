<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Core\Exceptions\NotFoundException;
use App\Core\ValidationException;
use App\Core\Request;
use App\Repositories\PlatformRepository;
use App\Repositories\TenantRepository;
use App\Services\Audit\AuditLogger;
use App\Support\LocalTime;

final class PlatformService
{
    private const SUBSCRIPTION_STATUSES = ['trial', 'active', 'past_due', 'cancelled', 'suspended'];

    public function __construct(
        private readonly PlatformRepository $platform,
        private readonly TenantRepository $tenants,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function overview(): array
    {
        return $this->platform->overview();
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function tenants(array $query): array
    {
        $status = trim((string) ($query['status'] ?? ''));
        $q = trim((string) ($query['q'] ?? ''));

        return array_map($this->present(...), $this->platform->tenants([
            'status' => $status === '' ? null : $status,
            'q' => $q === '' ? null : $q,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        $tenant = $this->tenant($id);
        $data = $this->present($tenant);
        $data['usage'] = $this->platform->usage($id);
        $data['plans'] = array_map(static fn (array $plan): array => [
            'code' => (string) $plan['code'],
            'name' => (string) $plan['name'],
            'monthly_price_minor' => (int) $plan['monthly_price_minor'],
            'currency' => (string) $plan['currency'],
            'max_agents' => $plan['max_agents'] === null ? null : (int) $plan['max_agents'],
            'max_tickets_per_month' => $plan['max_tickets_per_month'] === null
                ? null
                : (int) $plan['max_tickets_per_month'],
        ], $this->platform->plans());

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function suspend(int $id, array $input, Request $request, int $actorId): array
    {
        $tenant = $this->tenant($id);
        $reason = trim((string) ($input['reason'] ?? 'Suspended by a platform administrator.'));

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw new ValidationException(['reason' => 'Enter a reason between 3 and 255 characters.']);
        }

        if ((string) $tenant['status'] !== 'suspended') {
            $this->tenants->update($id, [
                'status' => 'suspended',
                'suspended_at' => gmdate('Y-m-d H:i:s'),
                'suspended_reason' => $reason,
            ]);
            $this->audit->record($request, $id, $actorId, 'tenant.suspended', 'tenant', $id, [
                'reason' => $reason,
            ]);
        }

        return $this->tenant($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function reactivate(int $id, Request $request, int $actorId): array
    {
        $tenant = $this->tenant($id);

        if ((string) $tenant['status'] !== 'active') {
            $this->tenants->update($id, [
                'status' => 'active',
                'suspended_at' => null,
                'suspended_reason' => null,
            ]);
            $this->audit->record($request, $id, $actorId, 'tenant.reactivated', 'tenant', $id);
        }

        return $this->tenant($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function subscription(int $id, array $input, Request $request, int $actorId): array
    {
        $this->tenant($id);

        $planCode = trim((string) ($input['plan_code'] ?? ''));
        $plan = $planCode === '' ? null : $this->platform->planByCode($planCode);

        if ($plan === null) {
            throw new ValidationException(['plan_code' => 'Choose a valid plan.']);
        }

        $status = trim((string) ($input['status'] ?? 'active'));

        if (!in_array($status, self::SUBSCRIPTION_STATUSES, true)) {
            throw new ValidationException([
                'status' => 'Choose trial, active, past_due, cancelled or suspended.',
            ]);
        }

        $periodEnd = $this->date((string) ($input['current_period_end'] ?? ''), 'current_period_end');
        $current = $this->platform->subscription($id);

        $this->platform->saveSubscription($id, [
            'plan_id' => (int) $plan['id'],
            'status' => $status,
            'current_period_start' => $current['current_period_start'] ?? gmdate('Y-m-d H:i:s'),
            'current_period_end' => $periodEnd,
            'cancelled_at' => $status === 'cancelled' ? gmdate('Y-m-d H:i:s') : null,
            'notes' => mb_substr(trim((string) ($input['notes'] ?? '')), 0, 255) ?: null,
        ]);

        $this->audit->record($request, $id, $actorId, 'tenant.subscription_updated', 'tenant', $id, [
            'plan' => $planCode,
            'status' => $status,
        ]);

        return $this->tenant($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $outbox = $this->platform->outbox();

        return [
            'app' => [
                'name' => (string) config('app.name'),
                'environment' => (string) config('app.env'),
            ],
            'database' => [
                'reachable' => $this->platform->databaseReachable(),
            ],
            'outbox' => $outbox,
            'last_backup_at' => null,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenant(int $id): array
    {
        $tenant = $this->platform->tenant($id);

        if ($tenant === null) {
            throw new NotFoundException();
        }

        return $tenant;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'status' => (string) $row['status'],
            'timezone' => (string) $row['timezone'],
            'contact_email' => $row['contact_email'] === null ? null : (string) $row['contact_email'],
            'contact_phone' => $row['contact_phone'] === null ? null : (string) $row['contact_phone'],
            'agents' => (int) ($row['agent_count'] ?? 0),
            'plan' => $row['plan_code'] === null ? null : [
                'code' => (string) $row['plan_code'],
                'name' => (string) ($row['plan_name'] ?? ''),
                'status' => $row['subscription_status'] === null ? null : (string) $row['subscription_status'],
                'current_period_end' => $this->iso($row['current_period_end'] ?? null),
            ],
            'suspended_at' => $this->iso($row['suspended_at'] ?? null),
            'suspended_reason' => $row['suspended_reason'] === null ? null : (string) $row['suspended_reason'],
            'created_at' => $this->iso($row['created_at'] ?? null),
        ];
    }

    private function date(string $value, string $field): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            [$year, $month, $day] = array_map('intval', explode('-', $value));

            if (!checkdate($month, $day, $year)) {
                throw new ValidationException([$field => 'Enter a valid date.']);
            }

            return $value . ' 23:59:59';
        }

        $converted = LocalTime::inputToUtc($value, 'UTC');

        if ($converted === null) {
            throw new ValidationException([$field => 'Enter a valid date.']);
        }

        return $converted;
    }

    private function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
