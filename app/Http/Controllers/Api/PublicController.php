<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PublicAccess\PublicRequestService;
use App\Services\PublicAccess\PublicTenantGate;
use App\Services\PublicAccess\TrackingService;
use App\Services\Tenants\PublicSettings;
use App\Support\RateLimiter;

final class PublicController
{
    public function __construct(
        private readonly PublicTenantGate $gate,
        private readonly PublicRequestService $requests,
        private readonly TrackingService $tracking,
        private readonly PublicSettings $settings,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function configuration(Request $request, string $identifier): Response
    {
        $tenant = $this->gate->resolve($request->header('Host'), $identifier);

        return Response::json(['data' => $this->requests->configuration($tenant)]);
    }

    public function store(Request $request, string $identifier): Response
    {
        $tenant = $this->gate->resolve($request->header('Host'), $identifier);
        $result = $this->requests->submit(
            $tenant,
            $request->all(),
            $request->file('photo'),
            $request->ip(),
        );

        return Response::json([
            'data' => [
                'reference' => $result['reference'],
                'tracking_url' => $result['tracking_url'],
                'message' => $result['message'],
            ],
        ], 201);
    }

    public function track(Request $request, string $token): Response
    {
        $result = $this->tracking->view($token, $request->ip());

        return Response::json(['data' => $result['data']]);
    }

    public function reply(Request $request, string $token): Response
    {
        $result = $this->tracking->reply($token, (string) $request->input('body', ''), $request->ip());

        return Response::json(['data' => $result['data']], 201);
    }

    public function checkSlug(Request $request): Response
    {
        $retry = $this->limiter->attempt(['slugs:ip:' . $request->ip()], 20, 60);

        if ($retry !== null) {
            throw new HttpException(
                429,
                'Too many checks. Try again shortly.',
                ['Retry-After' => (string) $retry],
                'rate_limited',
            );
        }

        return Response::json([
            'data' => [
                'available' => $this->settings->slugAvailable((string) $request->input('slug', '')),
            ],
        ]);
    }
}
