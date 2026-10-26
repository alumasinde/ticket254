<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Core\Exceptions\NotFoundException;
use App\Database\Paginator;
use App\Repositories\TicketAttachmentRepository;
use App\Repositories\TicketEventRepository;
use App\Repositories\TicketMessageRepository;
use App\Repositories\TicketRepository;
use App\Services\Tenants\TenantContext;
use App\Support\TicketReference;

final class TicketReader
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketEventRepository $events,
        private readonly TicketMessageRepository $messages,
        private readonly TicketAttachmentRepository $attachments,
        private readonly TicketAccess $access,
    ) {
    }

    public function paginate(TenantContext $ctx, TicketFilters $filters): Paginator
    {
        return $this->tickets->paginate($ctx, $filters);
    }

    /**
     * @return array{row: array<string, mixed>, events: list<array<string, mixed>>, messages: list<array<string, mixed>>}
     */
    public function detail(TenantContext $ctx, string $reference): array
    {
        $number = TicketReference::parse($reference);

        if ($number === null) {
            throw new NotFoundException();
        }

        $row = $this->tickets->findByNumber($ctx, $number);

        if ($row === null || !$this->access->canView($ctx, $row)) {
            throw new NotFoundException();
        }

        return $this->withEvents($ctx, $row);
    }

    /**
     * The creator receives the ticket they just created, before team visibility applies.
     *
     * @return array{row: array<string, mixed>, events: list<array<string, mixed>>, messages: list<array<string, mixed>>}
     */
    public function forCreator(TenantContext $ctx, int $number): array
    {
        $row = $this->tickets->findByNumber($ctx, $number);

        if ($row === null) {
            throw new NotFoundException();
        }

        return $this->withEvents($ctx, $row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{row: array<string, mixed>, events: list<array<string, mixed>>, messages: list<array<string, mixed>>}
     */
    private function withEvents(TenantContext $ctx, array $row): array
    {
        return [
            'row' => $row,
            'events' => $this->events->forTicket($ctx, (int) $row['id']),
            'messages' => $this->messages->forTicket($ctx, (int) $row['id']),
            'attachments' => $this->attachments->forTicket($ctx->tenantId, (int) $row['id']),
        ];
    }
}
