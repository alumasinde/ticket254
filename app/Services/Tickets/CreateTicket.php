<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Core\ValidationException;
use App\Database\Database;
use App\Repositories\CategoryRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\SiteRepository;
use App\Repositories\TeamRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TicketCounterRepository;
use App\Repositories\TicketEventRepository;
use App\Repositories\TicketReferenceRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketTokenRepository;
use App\Services\Tenants\TenantContext;
use App\Support\LocalTime;
use App\Support\PublicUrl;
use App\Support\TicketReference;
use RuntimeException;

final class CreateTicket
{
    public function __construct(
        private readonly Database $db,
        private readonly TicketCounterRepository $counters,
        private readonly TicketRepository $tickets,
        private readonly TicketEventRepository $events,
        private readonly NotificationOutboxRepository $outbox,
        private readonly TicketReferenceRepository $references,
        private readonly CategoryRepository $categories,
        private readonly TeamRepository $teams,
        private readonly SiteRepository $sites,
        private readonly TenantRepository $tenants,
        private readonly TicketTokenRepository $tokens,
        private readonly PublicUrl $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{number: int, row: array<string, mixed>, events: list<array<string, mixed>>, tracking_token: string, tracking_url: string}
     */
    public function execute(TenantContext $ctx, array $input): array
    {
        return $this->create(
            $ctx->tenantId,
            $ctx->userId,
            'staff',
            $ctx->timezone,
            $input,
            true,
            fn (int $number): ?array => $this->tickets->findByNumber($ctx, $number),
            fn (int $ticketId): array => $this->events->forTicket($ctx, $ticketId),
        );
    }

    /**
     * A public requester has no account, no team choice and no priority choice.
     *
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $input
     * @return array{number: int, row: array<string, mixed>, events: list<array<string, mixed>>, tracking_token: string, tracking_url: string}
     */
    public function executePublic(array $tenant, array $input): array
    {
        return $this->create(
            (int) $tenant['id'],
            null,
            'public',
            (string) $tenant['timezone'],
            $input,
            false,
            fn (int $number): ?array => $this->tickets->findByNumberForTenant((int) $tenant['id'], $number),
            static fn (int $ticketId): array => [],
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param callable(int): ?array $reload
     * @param callable(int): list<array<string, mixed>> $loadEvents
     * @return array{number: int, row: array<string, mixed>, events: list<array<string, mixed>>, tracking_token: string, tracking_url: string}
     */
    private function create(
        int $tenantId,
        ?int $actorUserId,
        string $source,
        string $timezone,
        array $input,
        bool $allowRoutingChoice,
        callable $reload,
        callable $loadEvents,
    ): array {
        $requesterName = trim((string) ($input['requester_name'] ?? ''));
        $requesterEmail = strtolower(trim((string) ($input['requester_email'] ?? '')));
        $requesterPhone = $this->nullableText($input['requester_phone'] ?? null);
        $subject = trim((string) ($input['subject'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $categoryId = $this->nullableId($input['category_id'] ?? null);
        $siteId = $this->nullableId($input['site_id'] ?? null);
        $teamId = $allowRoutingChoice ? $this->nullableId($input['team_id'] ?? null) : null;

        $category = null;

        if ($categoryId !== null) {
            $category = $this->categories->findForTenant($tenantId, $categoryId);

            if ($category === null || (string) $category['status'] !== 'active') {
                throw new ValidationException([
                    'category_id' => 'The selected category is not available in this workspace.',
                ]);
            }
        }

        if ($siteId !== null) {
            $site = $this->sites->findForTenant($tenantId, $siteId);

            if ($site === null || (string) $site['status'] !== 'active') {
                throw new ValidationException([
                    'site_id' => 'The selected site is not available in this workspace.',
                ]);
            }
        }

        if ($teamId !== null) {
            $team = $this->teams->findForTenant($tenantId, $teamId);

            if ($team === null || (string) $team['status'] !== 'active') {
                throw new ValidationException([
                    'team_id' => 'The selected team is not available in this workspace.',
                ]);
            }
        } elseif ($category !== null && $category['team_id'] !== null) {
            $teamId = (int) $category['team_id'];
        }

        $priority = $this->resolvePriority(
            $allowRoutingChoice ? ($input['priority'] ?? null) : null,
            $category,
        );
        $status = $this->references->statusByCode('open');

        if ($status === null) {
            throw new RuntimeException('The open ticket status is missing.');
        }

        $dueAt = $allowRoutingChoice
            ? LocalTime::inputToUtc($input['due_at'] ?? null, $timezone)
            : null;
        $dueAt ??= gmdate('Y-m-d H:i:s', time() + ((int) $priority['default_hours'] * 3600));

        $tenant = $this->tenants->findById($tenantId);

        if ($tenant === null) {
            throw new RuntimeException('The workspace could not be loaded.');
        }

        $token = TicketTokenRepository::newToken();

        $result = $this->db->transaction(function () use (
            $tenant,
            $tenantId,
            $actorUserId,
            $source,
            $requesterName,
            $requesterEmail,
            $requesterPhone,
            $subject,
            $description,
            $categoryId,
            $siteId,
            $teamId,
            $priority,
            $status,
            $dueAt,
            $token,
        ): array {
            $number = $this->counters->allocate($tenantId);

            $ticketId = $this->tickets->createForTenant($tenantId, [
                'ticket_number' => $number,
                'site_id' => $siteId,
                'category_id' => $categoryId,
                'requester_user_id' => null,
                'requester_name' => $requesterName,
                'requester_email' => $requesterEmail,
                'requester_phone' => $requesterPhone,
                'team_id' => $teamId,
                'assigned_user_id' => null,
                'subject' => $subject,
                'description' => $description,
                'status_id' => (int) $status['id'],
                'priority_id' => (int) $priority['id'],
                'source' => $source,
                'version' => 1,
                'due_at' => $dueAt,
            ]);

            $reference = TicketReference::format($number);

            $this->tokens->create($tenantId, $ticketId, $token);

            $this->events->record(
                $tenantId,
                $ticketId,
                $actorUserId,
                'created',
                null,
                [
                    'reference' => $reference,
                    'status' => (string) $status['code'],
                    'priority' => (string) $priority['code'],
                    'team_id' => $teamId,
                    'category_id' => $categoryId,
                ],
            );

            $this->outbox->enqueue(
                $tenantId,
                $ticketId,
                'ticket_created',
                $requesterEmail,
                $requesterName,
                [
                    'reference' => $reference,
                    'subject' => $subject,
                    'requester_name' => $requesterName,
                    'workspace_name' => (string) $tenant['name'],
                    'tracking_url' => $this->urls->track($tenant, $token),
                ],
            );

            return [
                'number' => $number,
                'ticket_id' => $ticketId,
            ];
        });

        $row = $reload($result['number']);

        if ($row === null) {
            throw new RuntimeException('The created ticket could not be reloaded.');
        }

        return [
            'number' => $result['number'],
            'row' => $row,
            'events' => $loadEvents((int) $result['ticket_id']),
            'tracking_token' => $token,
            'tracking_url' => $this->urls->track($tenant, $token),
        ];
    }

    /**
     * @param array<string, mixed>|null $category
     * @return array<string, mixed>
     */
    private function resolvePriority(mixed $code, ?array $category): array
    {
        $priority = null;

        if (is_string($code) && $code !== '') {
            $priority = $this->references->priorityByCode($code);
        } elseif ($category !== null && $category['default_priority_id'] !== null) {
            $priority = $this->references->priorityById((int) $category['default_priority_id']);
        }

        $priority ??= $this->references->priorityByCode('normal');

        if ($priority === null) {
            throw new RuntimeException('The normal ticket priority is missing.');
        }

        return $priority;
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
