<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Services\Auth\SessionAuth;
use Closure;

final class RequirePlatformAdmin implements Middleware
{
    public function __construct(
        private readonly SessionAuth $auth,
        private readonly UserRepository $users,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $this->auth->userId();

        if ($userId === null) {
            throw new HttpException(401, 'Please sign in to continue.', [], 'unauthenticated');
        }

        $user = $this->users->findById($userId);

        if ($user === null || (string) $user['status'] !== 'active') {
            $this->auth->destroy();

            throw new HttpException(401, 'Your session is no longer valid. Please sign in again.', [], 'unauthenticated');
        }

        if ((int) $user['auth_version'] !== $this->auth->authVersion()) {
            $this->auth->destroy();

            throw new HttpException(401, 'Your session is no longer valid. Please sign in again.', [], 'unauthenticated');
        }

        if ((int) $user['is_platform_admin'] !== 1) {
            throw new HttpException(403, 'Platform access is required.', [], 'forbidden');
        }

        return $next($request);
    }
}
