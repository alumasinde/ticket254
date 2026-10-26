<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Core\Exceptions\InvalidTransitionException;
use App\Core\Exceptions\VersionConflictException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Http\Requests\Tickets\ChangePriorityRequest;
use App\Http\Requests\Tickets\StoreMessageRequest;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\TransitionTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Repositories\CategoryRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\SiteRepository;
use App\Repositories\TeamRepository;
use App\Repositories\TenantRepository;
use App\Repositories\TicketReferenceRepository;
use App\Repositories\UserRepository;
use App\Services\Tickets\CreateTicket;
use App\Services\Tickets\TicketAccess;
use App\Services\Tickets\TicketFilters;
use App\Services\Tickets\TicketLifecycle;
use App\Services\Tickets\TicketPresenter;
use App\Services\Tickets\TicketReader;
use App\Services\Tickets\TrackingLinkService;
use App\Services\Tickets\UpdateTicket;
use App\Services\Tickets\WorkTicket;
use App\Services\Tenants\TenantContext;
use App\Support\TicketReference;

final class TicketController extends WorkspaceController
{
    public function __construct(
        UserRepository $users,
        TenantRepository $tenants,
        private readonly TicketReader $reader,
        private readonly CreateTicket $createTicket,
        private readonly UpdateTicket $updateTicket,
        private readonly WorkTicket $work,
        private readonly TicketAccess $access,
        private readonly TicketReferenceRepository $references,
        private readonly TeamRepository $teams,
        private readonly CategoryRepository $categories,
        private readonly SiteRepository $sites,
        private readonly MembershipRepository $memberships,
        private readonly TrackingLinkService $trackingLinks,
    ) {
        parent::__construct($users, $tenants);
    }

    public function index(Request $request, TenantContext $context): Response
    {
        $filters = TicketFilters::fromQuery($request->query());
        $paginator = $this->reader->paginate($context, $filters);

        return $this->page($context, 'app/tickets/index.twig', [
            'rows' => array_map(TicketPresenter::summary(...), $paginator->items()),
            'meta' => $paginator->meta(),
            'filters' => $filters->toView(),
            'filter_query' => $filters->toQuery(),
            'statuses' => $this->references->statuses(),
            'priorities' => $this->references->priorities(),
            'teams' => $this->teams->all($context),
            'members' => $this->memberships->activeMembersForTenant($context->tenantId),
        ], 'tickets');
    }

    public function create(TenantContext $context): Response
    {
        return $this->page($context, 'app/tickets/create.twig', [
            'categories' => $this->categories->all($context),
            'teams' => $this->teams->all($context),
            'sites' => $this->sites->all($context),
            'priorities' => $this->references->priorities(),
        ], 'tickets');
    }

    public function store(StoreTicketRequest $request, TenantContext $context): Response
    {
        $result = $this->createTicket->execute($context, $request->validated());
        $reference = TicketReference::format($result['number']);

        return redirect(route('tickets.show', ['ref' => $reference]))
            ->with('success', "Ticket {$reference} created.");
    }

    public function show(string $ref, TenantContext $context): Response
    {
        $detail = $this->reader->detail($context, $ref);
        $allowedCodes = TicketLifecycle::allowedFrom((string) $detail['row']['status_code']);
        $allowedStatuses = array_values(array_filter(
            $this->references->statuses(),
            static fn (array $status): bool => in_array((string) $status['code'], $allowedCodes, true),
        ));

        return $this->page($context, 'app/tickets/show.twig', [
            'ticket' => TicketPresenter::detail(
                $detail['row'],
                $detail['events'],
                $detail['messages'],
                $detail['attachments'],
            ),
            'can_edit' => $this->access->canEdit($context, $detail['row']),
            'can_assign' => $context->can('tickets.assign') || $context->can('tickets.assign_self'),
            'can_assign_any' => $context->can('tickets.assign'),
            'can_status' => $context->can('tickets.status'),
            'can_priority' => $context->can('tickets.priority'),
            'can_reply' => $context->can('tickets.reply'),
            'can_note' => $context->can('tickets.note'),
            'categories' => $this->categories->all($context),
            'sites' => $this->sites->all($context),
            'teams' => $this->teams->all($context),
            'members' => $this->memberships->activeMembersForTenant($context->tenantId),
            'priorities' => $this->references->priorities(),
            'allowed_statuses' => $allowedStatuses,
        ], 'tickets');
    }

    public function update(UpdateTicketRequest $request, string $ref, TenantContext $context): Response
    {
        try {
            $this->updateTicket->execute($context, $ref, $request->validated());
        } catch (VersionConflictException $exception) {
            return redirect(route('tickets.show', ['ref' => $ref]))
                ->with('error', $exception->getMessage());
        }

        return redirect(route('tickets.show', ['ref' => $ref]))
            ->with('success', 'Ticket updated.');
    }

    public function assign(AssignTicketRequest $request, string $ref, TenantContext $context): Response
    {
        try {
            $this->work->assign($context, $ref, $request->validated());
        } catch (VersionConflictException $exception) {
            return redirect(route('tickets.show', ['ref' => $ref]))
                ->with('error', $exception->getMessage());
        }

        return redirect(route('tickets.show', ['ref' => $ref]))
            ->with('success', 'Assignment updated.');
    }

    public function transition(TransitionTicketRequest $request, string $ref, TenantContext $context): Response
    {
        try {
            $this->work->transition($context, $ref, $request->validated());
        } catch (VersionConflictException | InvalidTransitionException $exception) {
            return redirect(route('tickets.show', ['ref' => $ref]))
                ->with('error', $exception->getMessage());
        }

        return redirect(route('tickets.show', ['ref' => $ref]))
            ->with('success', 'Status updated.');
    }

    public function priority(ChangePriorityRequest $request, string $ref, TenantContext $context): Response
    {
        try {
            $this->work->changePriority($context, $ref, $request->validated());
        } catch (VersionConflictException $exception) {
            return redirect(route('tickets.show', ['ref' => $ref]))
                ->with('error', $exception->getMessage());
        }

        return redirect(route('tickets.show', ['ref' => $ref]))
            ->with('success', 'Priority updated.');
    }

    public function message(StoreMessageRequest $request, string $ref, TenantContext $context): Response
    {
        $input = $request->validated();
        $this->work->addMessage($context, $ref, $input);

        return redirect(route('tickets.show', ['ref' => $ref]))
            ->with('success', ($input['visibility'] ?? 'public') === 'public' ? 'Reply sent.' : 'Internal note added.');
    }

    public function regenerateTracking(string $ref, TenantContext $context): Response
    {
        $result = $this->trackingLinks->regenerate($context, $ref);

        return redirect(route('tickets.show', ['ref' => $ref]))
            ->with('success', 'A new tracking link was created. The old link no longer works.')
            ->with('tracking_url', $result['tracking_url']);
    }
}
