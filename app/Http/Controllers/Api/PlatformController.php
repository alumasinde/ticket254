<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\SessionAuth;
use App\Services\Platform\PlatformService;

final class PlatformController
{
    public function __construct(
        private readonly PlatformService $platform,
        private readonly SessionAuth $auth,
    ) {
    }

    public function overview(): Response
    {
        return Response::json(['data' => $this->platform->overview()]);
    }

    public function tenants(Request $request): Response
    {
        return Response::json([
            'data' => $this->platform->tenants($request->query()),
        ]);
    }

    public function show(int $id): Response
    {
        return Response::json(['data' => $this->platform->detail($id)]);
    }

    public function suspend(Request $request, int $id): Response
    {
        return Response::json([
            'data' => $this->platform->suspend(
                $id,
                $request->all(),
                $request,
                (int) $this->auth->userId(),
            ),
        ]);
    }

    public function reactivate(Request $request, int $id): Response
    {
        return Response::json([
            'data' => $this->platform->reactivate($id, $request, (int) $this->auth->userId()),
        ]);
    }

    public function subscription(Request $request, int $id): Response
    {
        return Response::json([
            'data' => $this->platform->subscription(
                $id,
                $request->all(),
                $request,
                (int) $this->auth->userId(),
            ),
        ]);
    }

    public function health(): Response
    {
        return Response::json(['data' => $this->platform->health()]);
    }
}
