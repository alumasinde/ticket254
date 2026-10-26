<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\Dashboard\DashboardService;
use App\Services\Tenants\TenantContext;

final class DashboardController
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function show(Request $request, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->dashboard->data($context, $request->query()),
        ]);
    }
}
