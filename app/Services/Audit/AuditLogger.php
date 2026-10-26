<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Core\Request;
use App\Repositories\AuditLogRepository;

final class AuditLogger
{
    private const REDACTED = [
        'password', 'password_confirmation', 'token', 'token_hash', 'secret',
        'authorization', 'cookie', 'body', 'description', 'message', 'email', 'phone',
    ];

    public function __construct(private readonly AuditLogRepository $logs)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(
        ?Request $request,
        ?int $tenantId,
        ?int $actorUserId,
        string $action,
        string $resourceType,
        string|int|null $resourceId = null,
        array $metadata = [],
    ): void {
        $encoded = $metadata === []
            ? null
            : json_encode(
                $this->sanitize($metadata),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

        $this->logs->insert([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'action' => mb_substr($action, 0, 80),
            'resource_type' => mb_substr($resourceType, 0, 60),
            'resource_id' => $resourceId === null ? null : mb_substr((string) $resourceId, 0, 64),
            'ip_address' => $request?->ip(),
            'user_agent' => $this->userAgent($request),
            'metadata' => $encoded,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function sanitize(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            $name = strtolower((string) $key);

            if (in_array($name, self::REDACTED, true)
                || str_contains($name, 'password')
                || str_contains($name, 'token')
                || str_contains($name, 'secret')
            ) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $clean[$key] = $value;
                continue;
            }

            $clean[$key] = mb_substr((string) $value, 0, 190);
        }

        return $clean;
    }

    private function userAgent(?Request $request): ?string
    {
        $agent = $request?->header('User-Agent');

        return is_string($agent) && $agent !== '' ? mb_substr($agent, 0, 255) : null;
    }
}
