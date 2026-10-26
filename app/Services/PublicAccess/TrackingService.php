<?php

declare(strict_types=1);

namespace App\Services\PublicAccess;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\ValidationException;
use App\Database\Database;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TicketEventRepository;
use App\Repositories\TicketMessageRepository;
use App\Repositories\TicketReferenceRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketTokenRepository;
use App\Services\Tenants\TenantSettings;
use App\Support\PublicUrl;
use App\Support\RateLimiter;
use App\Support\TicketReference;
use RuntimeException;

final class TrackingService
{
    public function __construct(
        private readonly Database $db,
        private readonly TicketTokenRepository $tokens,
        private readonly TicketRepository $tickets,
        private readonly TicketMessageRepository $messages,
        private readonly TicketEventRepository $events,
        private readonly TicketReferenceRepository $references,
        private readonly TenantRepository $tenants,
        private readonly NotificationOutboxRepository $outbox,
        private readonly TenantSettings $settings,
        private readonly PublicUrl $urls,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * @return array{data: array<string, mixed>, tenant: array<string, mixed>, ticket: array<string, mixed>}
     */
    public function view(string $rawToken, string $ip): array
    {
        $this->guardLookup($ip);

        $token = $this->tokens->findActive($rawToken);

        if ($token === null) {
            throw new NotFoundException();
        }

        $tenant = $this->tenants->findById((int) $token['tenant_id']);
        $ticket = $this->tickets->findByIdForTenant((int) $token['tenant_id'], (int) $token['ticket_id']);

        if ($tenant === null || $ticket === null) {
            throw new NotFoundException();
        }

        $this->assertTenantActive($tenant);
        $this->tokens->touch((int) $token['id']);
        $canReply = $this->settings->allowsReplies($tenant) && (string) $ticket['status_code'] !== 'closed';

        return [
            'data' => [
                'reference' => TicketReference::format((int) $ticket['ticket_number']),
                'subject' => (string) $ticket['subject'],
                'status' => [
                    'code' => (string) $ticket['status_code'],
                    'label' => (string) $ticket['status_label'],
                ],
                'created_at' => $this->iso($ticket['created_at'] ?? null),
                'updated_at' => $this->iso($ticket['updated_at'] ?? null),
                'messages' => $this->publicMessages(
                    $this->messages->publicForTicket((int) $tenant['id'], (int) $ticket['id']),
                ),
                'can_reply' => $canReply,
            ],
            'tenant' => $tenant,
            'ticket' => $ticket,
        ];
    }

    /**
     * @return array{data: array<string, mixed>, tenant: array<string, mixed>, ticket: array<string, mixed>}
     */
    public function reply(string $rawToken, string $body, string $ip): array
    {
        $body = trim($body);

        if ($body === '') {
            throw new ValidationException(['body' => 'Write a message before sending.']);
        }

        if (mb_strlen($body) > 20000) {
            throw new ValidationException(['body' => 'The message may not be greater than 20000 characters.']);
        }

        $this->guardReply($rawToken);

        $token = $this->tokens->findActive($rawToken);

        if ($token === null) {
            throw new NotFoundException();
        }

        $tenant = $this->tenants->findById((int) $token['tenant_id']);
        $ticket = $this->tickets->findByIdForTenant((int) $token['tenant_id'], (int) $token['ticket_id']);

        if ($tenant === null || $ticket === null) {
            throw new NotFoundException();
        }

        $this->assertTenantActive($tenant);

        if (!$this->settings->allowsReplies($tenant) || (string) $ticket['status_code'] === 'closed') {
            throw new ValidationException(['body' => 'This request is not accepting replies right now.']);
        }

        $messageId = 0;

        $this->db->transaction(function () use ($tenant, $ticket, $body, &$messageId): void {
            $tenantId = (int) $tenant['id'];
            $ticketId = (int) $ticket['id'];

            $messageId = $this->messages->createRequester(
                $tenantId,
                $ticketId,
                (string) $ticket['requester_name'],
                $body,
            );

            $this->events->record($tenantId, $ticketId, null, 'requester_replied');

            if ((string) $ticket['status_code'] === 'pending') {
                $inProgress = $this->references->statusByCode('in_progress');

                if ($inProgress === null) {
                    throw new RuntimeException('The in-progress ticket status is missing.');
                }

                $this->tickets->updateForTenant($tenantId, $ticketId, [
                    'status_id' => (int) $inProgress['id'],
                    'resolved_at' => null,
                    'closed_at' => null,
                ]);

                $this->events->record(
                    $tenantId,
                    $ticketId,
                    null,
                    'status_changed',
                    ['status' => 'pending', 'label' => (string) $ticket['status_label']],
                    ['status' => (string) $inProgress['code'], 'label' => (string) $inProgress['label']],
                );
            }

            $this->enqueueStaffNotice($tenant, $ticket, $body);
        });

        $this->tokens->touch((int) $token['id']);

        $message = $this->messages->findPublic((int) $tenant['id'], (int) $ticket['id'], $messageId);

        if ($message === null) {
            throw new RuntimeException('The reply could not be reloaded.');
        }

        $fresh = $this->tickets->findByIdForTenant((int) $tenant['id'], (int) $ticket['id']);

        if ($fresh === null) {
            throw new RuntimeException('The ticket could not be reloaded.');
        }

        return [
            'data' => $this->publicMessage($message),
            'tenant' => $tenant,
            'ticket' => $fresh,
        ];
    }

    private function guardLookup(string $ip): void
    {
        $retry = $this->limiter->attempt(['track:ip:' . $ip], 30, 60);

        if ($retry !== null) {
            throw new HttpException(
                429,
                'Too many lookups. Try again shortly.',
                ['Retry-After' => (string) $retry],
                'rate_limited',
            );
        }
    }

    private function guardReply(string $rawToken): void
    {
        $retry = $this->limiter->attempt(
            ['track_reply:' . hash('sha256', $rawToken)],
            10,
            3600,
        );

        if ($retry !== null) {
            throw new HttpException(
                429,
                'Too many replies. Try again later.',
                ['Retry-After' => (string) $retry],
                'rate_limited',
            );
        }
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function assertTenantActive(array $tenant): void
    {
        if ((string) $tenant['status'] !== 'active') {
            throw new HttpException(
                403,
                'This workspace is unavailable.',
                [],
                'tenant_suspended',
                ['workspace_name' => (string) $tenant['name']],
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function publicMessages(array $messages): array
    {
        return array_map($this->publicMessage(...), $messages);
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function publicMessage(array $message): array
    {
        $mine = (string) ($message['source'] ?? '') === 'requester';

        return [
            'author' => $mine ? 'You' : 'Support team',
            'body' => (string) $message['body'],
            'created_at' => $this->iso($message['created_at'] ?? null),
            'mine' => $mine,
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $ticket
     */
    private function enqueueStaffNotice(array $tenant, array $ticket, string $body): void
    {
        $email = trim((string) ($ticket['assignee_email'] ?? ''));
        $name = trim((string) ($ticket['assignee_first_name'] ?? '') . ' ' . (string) ($ticket['assignee_last_name'] ?? ''));

        if ($email === '') {
            $email = trim((string) ($tenant['contact_email'] ?? ''));
            $name = (string) $tenant['name'];
        }

        if ($email === '') {
            return;
        }

        $reference = TicketReference::format((int) $ticket['ticket_number']);

        $this->outbox->enqueue(
            (int) $tenant['id'],
            (int) $ticket['id'],
            'ticket_requester_reply',
            $email,
            $name === '' ? null : $name,
            [
                'reference' => $reference,
                'subject' => (string) $ticket['subject'],
                'body' => $body,
                'requester_name' => (string) $ticket['requester_name'],
                'workspace_name' => (string) $tenant['name'],
                'staff_url' => $this->urls->staffTicket($tenant, $reference),
            ],
        );
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
