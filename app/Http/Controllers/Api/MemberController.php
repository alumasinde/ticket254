<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeamRepository;
use App\Services\Tenants\TenantContext;
use App\Services\Workspace\InvitationService;
use App\Services\Workspace\MemberService;

final class MemberController
{
    public function __construct(
        private readonly MemberService $members,
        private readonly InvitationService $invitations,
        private readonly TeamRepository $teams,
    ) {
    }

    public function index(TenantContext $context): Response
    {
        return Response::json([
            'data' => [
                'members' => $this->members->members($context),
                'invitations' => $this->invitations->pending($context),
                'roles' => $this->members->roles($context),
                'teams' => array_map(
                    static fn (array $team): array => [
                        'id' => (int) $team['id'],
                        'name' => (string) $team['name'],
                        'status' => (string) $team['status'],
                    ],
                    $this->teams->listWithCounts($context),
                ),
            ],
        ]);
    }

    public function update(Request $request, int $userId, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->members->update($context, $userId, $request->all(), $request),
        ]);
    }

    public function destroy(Request $request, int $userId, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->members->remove($context, $userId, $request),
        ]);
    }

    public function invite(Request $request, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->invitations->invite($context, $request->all(), $request),
        ], 201);
    }

    public function resend(Request $request, int $id, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->invitations->resend($context, $id, $request),
        ]);
    }

    public function revoke(Request $request, int $id, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->invitations->revoke($context, $id, $request),
        ]);
    }
}
