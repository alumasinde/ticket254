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
use App\Services\Tenants\TenantContext;
use App\Support\LocalTime;
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
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{number: int, row: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function execute(TenantContext $ctx, array $input): array
    {
        $requesterName = trim((string) ($input['requester_name'] ?? ''));
        $requesterEmail = strtolower(trim((string) ($input['requester_email'] ?? '')));
        $requesterPhone = $this->nullableText($input['requester_phone'] ?? null);
        $subject = trim((string) ($input['subject'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $categoryId = $this->nullableId($input['category_id'] ?? null);
        $siteId = $this->nullableId($input['site_id'] ?? null);
        $teamId = $this->nullableId($input['team_id'] ?? null);

        $category = null;

        if ($categoryId !== null) {
            $category = $this->categories->find($ctx, $categoryId);

            if ($category === null || (string) $category['status'] !== 'active') {
                throw new ValidationException([
                    'category_id' => 'The selected category is not available in this workspace.',
                ]);
            }
        }

        if ($siteId !== null) {
            $site = $this->sites->find($ctx, $siteId);

            if ($site === null || (string) $site['status'] !== 'active') {
                throw new ValidationException([
                    'site_id' => 'The selected site is not available in this workspace.',
                ]);
            }
        }

        if ($teamId !== null) {
            $team = $this->teams->find($ctx, $teamId);

            if ($team === null || (string) $team['status'] !== 'active') {
                throw new ValidationException([
                    'team_id' => 'The selected team is not available in this workspace.',
                ]);
            }
        } elseif ($category !== null && $category['team_id'] !== null) {
            $teamId = (int) $category['team_id'];
        }

        $priority = $this->resolvePriority($input['priority'] ?? null, $category);
        $status = $this->references->statusByCode('open');

        if ($status === null) {
            throw new RuntimeException('The open ticket status is missing.');
        }

        $dueAt = LocalTime::inputToUtc($input['due_at'] ?? null, $ctx->timezone)
            ?? gmdate('Y-m-d H:i:s', time() + ((int) $priority['default_hours'] * 3600));

        return $this->db->transaction(function () use (
            $ctx,
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
        ): array {
            $number = $this->counters->allocate($ctx->tenantId);

            $ticketId = $this->tickets->createForTenant($ctx->tenantId, [
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
                'source' => 'staff',
                'version' => 1,
                'due_at' => $dueAt,
            ]);

            $reference = TicketReference::format($number);

            $this->events->record(
                $ctx->tenantId,
                $ticketId,
                $ctx->userId,
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

            $tenant = $this->tenants->findById($ctx->tenantId);

            $this->outbox->enqueue(
                $ctx->tenantId,
                $ticketId,
                'ticket_created',
                $requesterEmail,
                $requesterName,
                [
                    'reference' => $reference,
                    'subject' => $subject,
                    'requester_name' => $requesterName,
                    'workspace_name' => (string) ($tenant['name'] ?? ''),
                ],
            );

            $row = $this->tickets->findByNumber($ctx, $number);

            if ($row === null) {
                throw new RuntimeException('The created ticket could not be reloaded.');
            }

            return [
                'number' => $number,
                'row' => $row,
                'events' => $this->events->forTicket($ctx, $ticketId),
            ];
        });
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
