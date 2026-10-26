<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\Audit\AuditService;
use App\Services\Tenants\TenantContext;

final class AuditController extends WorkspaceController
{
    public function __construct(
        UserRepository $users,
        TenantRepository $tenants,
        private readonly AuditService $audit,
    ) {
        parent::__construct($users, $tenants);
    }

    public function index(Request $request, TenantContext $context): Response
    {
        $page = $this->audit->page($context, $request->query());
        $query = $request->query();

        return $this->page($context, 'app/audit.twig', [
            'rows' => $page['rows'],
            'meta' => $page['meta'],
            'actions' => $this->audit->actions($context),
            'filters' => [
                'action' => (string) ($query['action'] ?? ''),
                'from' => (string) ($query['from'] ?? ''),
                'to' => (string) ($query['to'] ?? ''),
            ],
        ], 'audit');
    }
}
