<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Container;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\SessionAuth;
use App\Services\Tenants\TenantAccess;
use App\Services\Tenants\TenantContext;
use Closure;

final class ResolveTenant implements Middleware
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly SessionAuth $auth,
        private readonly Container $container,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $this->auth->userId();
        $tenantId = $this->auth->tenantId();

        if ($userId === null || $tenantId === null) {
            throw new ForbiddenException('No workspace is available for this account.');
        }

        try {
            $context = $this->access->contextFor($userId, $tenantId, $this->auth->authVersion());
        } catch (HttpException $exception) {
            if ($exception->status === 401) {
                $this->auth->destroy();

                if (!$request->expectsJson()) {
                    return redirect(route('login'));
                }
            }

            throw $exception;
        }

        $this->container->instance(TenantContext::class, $context);
        $request->setAttribute('tenant_context', $context);

        return $next($request);
    }
}
