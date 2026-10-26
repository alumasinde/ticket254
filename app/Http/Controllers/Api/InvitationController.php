<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\Workspace\InvitationService;

final class InvitationController
{
    public function __construct(private readonly InvitationService $invitations)
    {
    }

    public function accept(Request $request): Response
    {
        $result = $this->invitations->accept(
            (string) $request->input('token', ''),
            $request->all(),
            $request,
        );

        return Response::json([
            'data' => [
                'email' => $result['email'],
                'tenant_id' => $result['tenant_id'],
                'workspace_name' => $result['tenant_name'],
            ],
        ]);
    }
}
