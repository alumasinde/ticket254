<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\Audit\AuditService;
use App\Services\Tenants\TenantContext;

final class AuditController
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request, TenantContext $context): Response
    {
        $page = $this->audit->page($context, $request->query());

        return Response::json([
            'data' => $page['rows'],
            'meta' => $page['meta'],
            'actions' => $this->audit->actions($context),
        ]);
    }
}
