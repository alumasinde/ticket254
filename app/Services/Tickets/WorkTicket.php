<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\InvalidTransitionException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\VersionConflictException;
use App\Core\ValidationException;
use App\Database\Database;
use App\Repositories\MembershipRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\TeamRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TicketEventRepository;
use App\Repositories\TicketMessageRepository;
use App\Repositories\TicketReferenceRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketTokenRepository;
use App\Repositories\UserRepository;
use App\Services\Tenants\TenantContext;
use App\Support\LocalTime;
use App\Support\PublicUrl;
use App\Support\TicketReference;
use RuntimeException;

final class WorkTicket
{
    public function __construct(
        private readonly Database $db,
        private readonly TicketRepository $tickets,
        private readonly TicketEventRepository $events,
        private readonly TicketMessageRepository $messages,
        private readonly TicketAccess $access,
        private readonly TicketReferenceRepository $references,
        private readonly TeamRepository $teams,
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly NotificationOutboxRepository $outbox,
        private readonly TenantRepository $tenants,
        private readonly TicketTokenRepository $tokens,
        private readonly PublicUrl $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{row: array<string, mixed>, events: list<array<string, mixed>>, messages: list<array<string, mixed>>}
     */
    public function assign(TenantContext $ctx, string $reference, array $input): array
    {
        $row = $this->load($ctx, $reference);
        $teamProvided = array_key_exists('team_id', $input);
        $userProvided = array_key_exists('assigned_user_id', $input);

        if (!$teamProvided && !$userProvided) {
            throw new ValidationException(['assignment' => 'Choose a team or an agent.']);
        }

        $teamId = $teamProvided ? $this->teamId($ctx, $input['team_id']) : null;
        $team = $teamId === null ? null : $this->teams->find($ctx, $teamId);
        $assignedUserId = $userProvided ? $this->assigneeId($ctx, $input['assigned_user_id']) : null;
        $assignee = $assignedUserId === null ? null : $this->users->findById($assignedUserId);

        if (!$this->access->canAssign($ctx, $row, $teamId, $teamProvided, $assignedUserId)) {
            throw new ForbiddenException();
        }

        $currentTeam = ($row['team_id'] ?? null) === null ? null : (int) $row['team_id'];
        $currentUser = ($row['assigned_user_id'] ?? null) === null ? null : (int) $row['assigned_user_id'];
        $effectiveUser = $userProvided ? $assignedUserId : $currentUser;
        $data = [];
        $teamChanged = $teamProvided && $teamId !== $currentTeam;
        $userChanged = $userProvided && $assignedUserId !== $currentUser;

        if ($teamChanged) {
            $data['team_id'] = $teamId;
        }

        if ($userChanged) {
            $data['assigned_user_id'] = $assignedUserId;
        }

        $statusChanged = false;
        $assignedStatus = null;

        if ($effectiveUser !== null && (string) $row['status_code'] === 'open') {
            $assignedStatus = $this->references->statusByCode('assigned');

            if ($assignedStatus === null) {
                throw new RuntimeException('The assigned ticket status is missing.');
            }

            $data['status_id'] = (int) $assignedStatus['id'];
            $statusChanged = true;
        }

        if ($data === []) {
            return $this->detailByNumber($ctx, (int) $row['ticket_number']);
        }

        $old = [
            'team_id' => $currentTeam,
            'team' => $row['team_name'] === null ? null : (string) $row['team_name'],
            'assigned_user_id' => $currentUser,
            'assignee' => $this->assigneeName($row),
        ];
        $new = [
            'team_id' => $teamProvided ? $teamId : $currentTeam,
            'team' => $teamProvided
                ? ($team === null ? null : (string) $team['name'])
                : ($row['team_name'] === null ? null : (string) $row['team_name']),
            'assigned_user_id' => $userProvided ? $assignedUserId : $currentUser,
            'assignee' => $userProvided
                ? ($assignee === null ? null : $this->userName($assignee))
                : $this->assigneeName($row),
        ];

        $version = (int) $input['version'];

        $this->db->transaction(function () use ($ctx, $row, $version, $data, $old, $new, $teamChanged, $userChanged, $statusChanged, $assignedStatus): void {
            $this->update($ctx, (int) $row['id'], $version, $data);

            if ($teamChanged || $userChanged) {
                $this->events->record($ctx->tenantId, (int) $row['id'], $ctx->userId, 'assigned', $old, $new);
            }

            if ($statusChanged && $assignedStatus !== null) {
                $this->events->record(
                    $ctx->tenantId,
                    (int) $row['id'],
                    $ctx->userId,
                    'status_changed',
                    ['status' => (string) $row['status_code'], 'label' => (string) $row['status_label']],
                    ['status' => (string) $assignedStatus['code'], 'label' => (string) $assignedStatus['label']],
                );
            }
        });

        return $this->detailByNumber($ctx, (int) $row['ticket_number']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{row: array<string, mixed>, events: list<array<string, mixed>>, messages: list<array<string, mixed>>}
     */
    public function transition(TenantContext $ctx, string $reference, array $input): array
    {
        $row = $this->load($ctx, $reference);

        if (!$ctx->can('tickets.status')) {
            throw new ForbiddenException();
        }

        $to = trim((string) ($input['status'] ?? ''));
        $target = $this->references->statusByCode($to);

        if ($target === null) {
            throw new ValidationException(['status' => 'Choose a valid status.']);
        }

        $from = (string) $row['status_code'];
        $allowed = TicketLifecycle::allowedFrom($from);

        if (!in_array($to, $allowed, true)) {
            throw new InvalidTransitionException($from, $to, $allowed);
        }

        $note = trim((string) ($input['note'] ?? ''));

        if ($note !== '' && !$ctx->can('tickets.note')) {
            throw new ForbiddenException();
        }

        $now = gmdate('Y-m-d H:i:s');
        $data = ['status_id' => (int) $target['id']];

        if ($to === 'resolved') {
            $data['resolved_at'] = $now;
            $data['closed_at'] = null;
        } elseif ($to === 'closed') {
            $data['closed_at'] = $now;
        } else {
            $data['resolved_at'] = null;
            $data['closed_at'] = null;
        }

        $version = (int) $input['version'];

        $this->db->transaction(function () use ($ctx, $row, $version, $data, $from, $target, $note, $now): void {
            $this->update($ctx, (int) $row['id'], $version, $data);

            $this->events->record(
                $ctx->tenantId,
                (int) $row['id'],
                $ctx->userId,
                'status_changed',
                ['status' => $from, 'label' => (string) $row['status_label']],
                ['status' => (string) $target['code'], 'label' => (string) $target['label']],
            );

            if ($note !== '') {
                $this->messages->create(
                    $ctx->tenantId,
                    (int) $row['id'],
                    $ctx->userId,
                    $this->actorName($ctx),
                    'internal',
                    $note,
                );
            }
        });

        return $this->detailByNumber($ctx, (int) $row['ticket_number']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{row: array<string, mixed>, events: list<array<string, mixed>>, messages: list<array<string, mixed>>}
     */
    public function changePriority(TenantContext $ctx, string $reference, array $input): array
    {
        $row = $this->load($ctx, $reference);

        if (!$ctx->can('tickets.priority')) {
            throw new ForbiddenException();
        }

        $code = trim((string) ($input['priority'] ?? ''));
        $priority = $this->references->priorityByCode($code);

        if ($priority === null) {
            throw new ValidationException(['priority' => 'Choose a valid priority.']);
        }

        $dueInput = $input['due_at'] ?? null;
        $dueAt = LocalTime::inputToUtc(is_string($dueInput) ? $dueInput : null, $ctx->timezone);

        if (is_string($dueInput) && trim($dueInput) !== '' && $dueAt === null) {
            throw new ValidationException(['due_at' => 'Enter a valid due date and time.']);
        }

        $dueAt ??= gmdate('Y-m-d H:i:s', time() + ((int) $priority['default_hours'] * 3600));
        $priorityChanged = $code !== (string) $row['priority_code'];
        $dueChanged = $row['due_at'] === null || (string) $row['due_at'] !== $dueAt;
        $data = [];

        if ($priorityChanged) {
            $data['priority_id'] = (int) $priority['id'];
        }

        if ($dueChanged) {
            $data['due_at'] = $dueAt;
        }

        if ($data === []) {
            return $this->detailByNumber($ctx, (int) $row['ticket_number']);
        }

        $version = (int) $input['version'];

        $this->db->transaction(function () use ($ctx, $row, $version, $data, $priorityChanged, $dueChanged, $priority, $dueAt): void {
            $this->update($ctx, (int) $row['id'], $version, $data);

            if ($priorityChanged) {
                $this->events->record(
                    $ctx->tenantId,
                    (int) $row['id'],
                    $ctx->userId,
                    'priority_changed',
                    ['priority' => (string) $row['priority_code'], 'label' => (string) $row['priority_label']],
                    ['priority' => (string) $priority['code'], 'label' => (string) $priority['label']],
                );
            }

            if ($dueChanged) {
                $this->events->record(
                    $ctx->tenantId,
                    (int) $row['id'],
                    $ctx->userId,
                    'due_changed',
                    ['due_at' => $this->iso($row['due_at'] ?? null)],
                    ['due_at' => $this->iso($dueAt)],
                );
            }
        });

        return $this->detailByNumber($ctx, (int) $row['ticket_number']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addMessage(TenantContext $ctx, string $reference, array $input): array
    {
        $row = $this->load($ctx, $reference);
        $visibility = (string) ($input['visibility'] ?? 'public');

        if (!in_array($visibility, ['public', 'internal'], true)) {
            throw new ValidationException(['visibility' => 'Choose a public reply or an internal note.']);
        }

        $permission = $visibility === 'public' ? 'tickets.reply' : 'tickets.note';

        if (!$ctx->can($permission)) {
            throw new ForbiddenException();
        }

        $body = trim((string) ($input['body'] ?? ''));

        if ($body === '') {
            throw new ValidationException(['body' => 'Write a message before sending.']);
        }

        $authorName = $this->actorName($ctx);
        $id = 0;

        $this->db->transaction(function () use ($ctx, $row, $visibility, $body, $authorName, &$id): void {
            $id = $this->messages->create(
                $ctx->tenantId,
                (int) $row['id'],
                $ctx->userId,
                $authorName,
                $visibility,
                $body,
            );

            if ($visibility !== 'public') {
                return;
            }

            $this->messages->markFirstResponse($ctx->tenantId, (int) $row['id']);

            $tenant = $this->tenants->findById($ctx->tenantId);
            $token = TicketTokenRepository::newToken();

            $this->tokens->create($ctx->tenantId, (int) $row['id'], $token);

            $this->outbox->enqueue(
                $ctx->tenantId,
                (int) $row['id'],
                'ticket_reply',
                (string) $row['requester_email'],
                (string) $row['requester_name'],
                [
                    'reference' => TicketReference::format((int) $row['ticket_number']),
                    'subject' => (string) $row['subject'],
                    'body' => $body,
                    'author_name' => $authorName,
                    'requester_name' => (string) $row['requester_name'],
                    'workspace_name' => (string) ($tenant['name'] ?? ''),
                    'tracking_url' => $tenant === null ? '' : $this->urls->track($tenant, $token),
                ],
            );
        });

        $message = $this->messages->find($ctx, (int) $row['id'], $id);

        if ($message === null) {
            throw new RuntimeException('The created message could not be reloaded.');
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(TenantContext $ctx, string $reference): array
    {
        $number = TicketReference::parse($reference);

        if ($number === null) {
            throw new NotFoundException();
        }

        $row = $this->tickets->findByNumber($ctx, $number);

        if ($row === null || !$this->access->canView($ctx, $row)) {
            throw new NotFoundException();
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function update(TenantContext $ctx, int $ticketId, int $version, array $data): void
    {
        if ($this->tickets->updateVersioned($ctx, $ticketId, $version, $data) === 0) {
            $current = $this->tickets->currentVersion($ctx, $ticketId);

            throw new VersionConflictException($current ?? $version);
        }
    }

    /**
     * @return array{row: array<string, mixed>, events: list<array<string, mixed>>, messages: list<array<string, mixed>>}
     */
    private function detailByNumber(TenantContext $ctx, int $number): array
    {
        $row = $this->tickets->findByNumber($ctx, $number);

        if ($row === null) {
            throw new RuntimeException('The ticket could not be reloaded.');
        }

        return [
            'row' => $row,
            'events' => $this->events->forTicket($ctx, (int) $row['id']),
            'messages' => $this->messages->forTicket($ctx, (int) $row['id']),
        ];
    }

    private function teamId(TenantContext $ctx, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $team = $this->teams->find($ctx, (int) $value);

        if ($team === null || (string) $team['status'] !== 'active') {
            throw new ValidationException(['team_id' => 'The selected team is not available in this workspace.']);
        }

        return (int) $value;
    }

    private function assigneeId(TenantContext $ctx, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $membership = $this->memberships->membershipForUserAndTenant($ctx->tenantId, (int) $value);

        if ($membership === null
            || (string) $membership['membership_status'] !== 'active'
            || (string) $membership['user_status'] !== 'active') {
            throw new ValidationException([
                'assigned_user_id' => 'The selected agent is not an active member of this workspace.',
            ]);
        }

        return (int) $value;
    }

    private function actorName(TenantContext $ctx): string
    {
        $user = $this->users->findById($ctx->userId);

        return $user === null ? 'Staff' : $this->userName($user);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assigneeName(array $row): ?string
    {
        if (($row['assigned_user_id'] ?? null) === null) {
            return null;
        }

        return trim((string) ($row['assignee_first_name'] ?? '') . ' ' . (string) ($row['assignee_last_name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function userName(array $user): string
    {
        return trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
    }

    private function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
