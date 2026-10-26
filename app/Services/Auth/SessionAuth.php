<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\SessionInterface;

final class SessionAuth
{
    private const AUTH = '_auth';
    private const CREATED = '_auth_created_at';
    private const LAST_ACTIVITY = '_auth_last_activity';

    public function __construct(
        private readonly SessionInterface $session,
        private readonly Config $config,
    ) {
    }

    public function start(int $userId, ?int $tenantId, int $authVersion): void
    {
        $now = time();

        $this->session->regenerate();
        $this->session->put(self::AUTH, [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'auth_version' => $authVersion,
        ]);
        $this->session->put(self::CREATED, $now);
        $this->session->put(self::LAST_ACTIVITY, $now);
        $this->session->forget('_token');
    }

    public function check(): bool
    {
        $auth = $this->auth();

        return $auth !== null
            && is_int($this->session->get(self::CREATED))
            && is_int($this->session->get(self::LAST_ACTIVITY));
    }

    public function userId(): ?int
    {
        return $this->auth()['user_id'] ?? null;
    }

    public function tenantId(): ?int
    {
        return $this->auth()['tenant_id'] ?? null;
    }

    public function authVersion(): int
    {
        return $this->auth()['auth_version'] ?? 0;
    }

    public function hasExpired(): bool
    {
        $created = $this->session->get(self::CREATED);
        $lastActivity = $this->session->get(self::LAST_ACTIVITY);

        if (!is_int($created) || !is_int($lastActivity)) {
            return true;
        }

        $now = time();
        $idleSeconds = ((int) $this->config->get('security.session.lifetime', 120)) * 60;
        $absoluteSeconds = ((int) $this->config->get('security.session.absolute_lifetime', 720)) * 60;

        return ($now - $lastActivity) > $idleSeconds
            || ($now - $created) > $absoluteSeconds;
    }

    public function touch(): void
    {
        $this->session->put(self::LAST_ACTIVITY, time());
    }

    public function destroy(): void
    {
        $this->session->destroy();
    }

    /**
     * @return array{user_id: int, tenant_id: ?int, auth_version: int}|null
     */
    private function auth(): ?array
    {
        $auth = $this->session->get(self::AUTH);

        if (!is_array($auth)
            || !isset($auth['user_id'], $auth['auth_version'])
            || !is_int($auth['user_id'])
            || !is_int($auth['auth_version'])
        ) {
            return null;
        }

        $tenantId = $auth['tenant_id'] ?? null;

        return [
            'user_id' => $auth['user_id'],
            'tenant_id' => is_int($tenantId) ? $tenantId : null,
            'auth_version' => $auth['auth_version'],
        ];
    }
}
