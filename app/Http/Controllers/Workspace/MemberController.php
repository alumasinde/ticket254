<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeamRepository;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\Tenants\TenantContext;
use App\Services\Workspace\InvitationService;
use App\Services\Workspace\MemberService;

final class MemberController extends WorkspaceController
{
    public function __construct(
        UserRepository $users,
        TenantRepository $tenants,
        private readonly MemberService $members,
        private readonly InvitationService $invitations,
        private readonly TeamRepository $teams,
    ) {
        parent::__construct($users, $tenants);
    }

    public function index(TenantContext $context): Response
    {
        return $this->page($context, 'app/members.twig', [
            'members' => $this->members->members($context),
            'invitations' => $this->invitations->pending($context),
            'roles' => $this->members->roles($context),
            'teams' => $this->teams->activeForTenant($context->tenantId),
        ], 'members');
    }

    public function update(Request $request, int $userId, TenantContext $context): Response
    {
        $this->members->update($context, $userId, $request->all(), $request);

        return redirect(route('members.index'))->with('success', 'Member updated.');
    }

    public function remove(Request $request, int $userId, TenantContext $context): Response
    {
        $this->members->remove($context, $userId, $request);

        return redirect(route('members.index'))->with('success', 'Member removed from this workspace.');
    }

    public function invite(Request $request, TenantContext $context): Response
    {
        $result = $this->invitations->invite($context, $request->all(), $request);

        return redirect(route('members.index'))
            ->with('success', 'Invitation sent to ' . $result['email'] . '.')
            ->with('invitation_url', $result['accept_url']);
    }

    public function resend(Request $request, int $id, TenantContext $context): Response
    {
        $result = $this->invitations->resend($context, $id, $request);

        return redirect(route('members.index'))
            ->with('success', 'Invitation resent.')
            ->with('invitation_url', $result['accept_url']);
    }

    public function revoke(Request $request, int $id, TenantContext $context): Response
    {
        $this->invitations->revoke($context, $id, $request);

        return redirect(route('members.index'))->with('success', 'Invitation revoked.');
    }
}
