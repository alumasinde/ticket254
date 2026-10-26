<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Services\Tickets\CreateTicket;
use App\Services\Tickets\TicketFilters;
use App\Services\Tickets\TicketPresenter;
use App\Services\Tickets\TicketReader;
use App\Services\Tickets\UpdateTicket;
use App\Services\Tenants\TenantContext;

final class TicketController
{
    public function __construct(
        private readonly TicketReader $reader,
        private readonly CreateTicket $createTicket,
        private readonly UpdateTicket $updateTicket,
    ) {
    }

    public function index(Request $request, TenantContext $context): Response
    {
        $paginator = $this->reader->paginate($context, TicketFilters::fromQuery($request->query()));

        return Response::json([
            'data' => array_map(TicketPresenter::summary(...), $paginator->items()),
            'meta' => $paginator->meta(),
        ]);
    }

    public function store(StoreTicketRequest $request, TenantContext $context): Response
    {
        $result = $this->createTicket->execute($context, $request->validated());

        return Response::json([
            'data' => TicketPresenter::detail($result['row'], $result['events']),
        ], 201);
    }

    public function show(string $ref, TenantContext $context): Response
    {
        $detail = $this->reader->detail($context, $ref);

        return Response::json([
            'data' => TicketPresenter::detail($detail['row'], $detail['events']),
        ]);
    }

    public function update(UpdateTicketRequest $request, string $ref, TenantContext $context): Response
    {
        $result = $this->updateTicket->execute($context, $ref, $request->validated());

        return Response::json([
            'data' => TicketPresenter::detail($result['row'], $result['events']),
        ]);
    }
}
