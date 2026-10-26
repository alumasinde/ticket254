<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Http\Requests\Tickets\ChangePriorityRequest;
use App\Http\Requests\Tickets\StoreMessageRequest;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\TransitionTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Repositories\TicketAttachmentRepository;
use App\Services\Tickets\CreateTicket;
use App\Services\Tickets\TicketAttachmentService;
use App\Services\Tickets\TicketFilters;
use App\Services\Tickets\TicketPresenter;
use App\Services\Tickets\TicketReader;
use App\Services\Tickets\TrackingLinkService;
use App\Services\Tickets\UpdateTicket;
use App\Services\Tickets\WorkTicket;
use App\Services\Tenants\TenantContext;

final class TicketController
{
    public function __construct(
        private readonly TicketReader $reader,
        private readonly CreateTicket $createTicket,
        private readonly UpdateTicket $updateTicket,
        private readonly WorkTicket $work,
        private readonly TicketAttachmentRepository $attachments,
        private readonly TicketAttachmentService $attachmentFiles,
        private readonly TrackingLinkService $trackingLinks,
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
            'data' => TicketPresenter::detail($detail['row'], $detail['events'], $detail['messages'], $detail['attachments']),
        ]);
    }

    public function update(UpdateTicketRequest $request, string $ref, TenantContext $context): Response
    {
        $this->updateTicket->execute($context, $ref, $request->validated());
        $detail = $this->reader->detail($context, $ref);

        return Response::json([
            'data' => TicketPresenter::detail($detail['row'], $detail['events'], $detail['messages'], $detail['attachments']),
        ]);
    }

    public function assign(AssignTicketRequest $request, string $ref, TenantContext $context): Response
    {
        $this->work->assign($context, $ref, $request->validated());

        return Response::json(['data' => $this->detail($context, $ref)]);
    }

    public function transition(TransitionTicketRequest $request, string $ref, TenantContext $context): Response
    {
        $this->work->transition($context, $ref, $request->validated());

        return Response::json(['data' => $this->detail($context, $ref)]);
    }

    public function priority(ChangePriorityRequest $request, string $ref, TenantContext $context): Response
    {
        $this->work->changePriority($context, $ref, $request->validated());

        return Response::json(['data' => $this->detail($context, $ref)]);
    }

    public function message(StoreMessageRequest $request, string $ref, TenantContext $context): Response
    {
        $message = $this->work->addMessage($context, $ref, $request->validated());

        return Response::json(['data' => TicketPresenter::message($message)], 201);
    }

    public function uploadAttachment(Request $request, string $ref, TenantContext $context): Response
    {
        $detail = $this->reader->detail($context, $ref);
        $attachment = $this->attachmentFiles->attach(
            $context,
            (int) $detail['row']['id'],
            $request->file('file') ?? [],
            $request,
        );

        return Response::json(['data' => TicketPresenter::attachment($attachment)], 201);
    }

    public function deleteAttachment(Request $request, string $ref, int $attachmentId, TenantContext $context): Response
    {
        $detail = $this->reader->detail($context, $ref);
        $this->attachmentFiles->remove($context, (int) $detail['row']['id'], $attachmentId, $request);

        return Response::noContent();
    }

    public function download(string $ref, int $attachmentId, TenantContext $context): Response
    {        $detail = $this->reader->detail($context, $ref);
        $attachment = $this->attachments->find($context->tenantId, (int) $detail['row']['id'], $attachmentId);

        if ($attachment === null) {
            throw new \App\Core\Exceptions\NotFoundException();
        }

        $path = $this->attachmentFiles->path($context->tenantId, $attachment);

        if ($path === null) {
            throw new \App\Core\Exceptions\NotFoundException();
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \App\Core\Exceptions\NotFoundException();
        }

        $name = str_replace(['"', '\\', "\r", "\n"], '', (string) $attachment['original_name']);

        return new Response($contents, 200, [
            'Content-Type' => (string) $attachment['mime_type'],
            'Content-Disposition' => 'attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function regenerateTracking(string $ref, TenantContext $context): Response
    {
        return Response::json(['data' => $this->trackingLinks->regenerate($context, $ref)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(TenantContext $context, string $ref): array
    {
        $detail = $this->reader->detail($context, $ref);

        return TicketPresenter::detail(
            $detail['row'],
            $detail['events'],
            $detail['messages'],
            $detail['attachments'],
        );
    }
}
