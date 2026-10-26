<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Database\Database;
use App\Repositories\TenantRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketTokenRepository;
use App\Services\Tenants\TenantContext;
use App\Support\PublicUrl;
use App\Support\TicketReference;
use RuntimeException;

final class TrackingLinkService
{
    public function __construct(
        private readonly Database $db,
        private readonly TicketRepository $tickets,
        private readonly TicketTokenRepository $tokens,
        private readonly TenantRepository $tenants,
        private readonly TicketAccess $access,
        private readonly PublicUrl $urls,
    ) {
    }

    /**
     * @return array{tracking_url: string}
     */
    public function regenerate(TenantContext $context, string $reference): array
    {
        if (!$context->can('tickets.status')) {
            throw new ForbiddenException();
        }

        $number = TicketReference::parse($reference);

        if ($number === null) {
            throw new NotFoundException();
        }

        $row = $this->tickets->findByNumber($context, $number);

        if ($row === null || !$this->access->canView($context, $row)) {
            throw new NotFoundException();
        }

        $tenant = $this->tenants->findById($context->tenantId);

        if ($tenant === null) {
            throw new RuntimeException('The workspace could not be loaded.');
        }

        $token = TicketTokenRepository::newToken();

        $this->db->transaction(function () use ($context, $row, $token): void {
            $this->tokens->revokeForTicket($context->tenantId, (int) $row['id']);
            $this->tokens->create($context->tenantId, (int) $row['id'], $token);
        });

        return ['tracking_url' => $this->urls->track($tenant, $token)];
    }
}
