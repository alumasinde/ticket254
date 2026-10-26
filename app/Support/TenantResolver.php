<?php

declare(strict_types=1);

namespace App\Support;

use App\Repositories\SlugHistoryRepository;
use App\Repositories\TenantRepository;

final class TenantResolver
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly SlugHistoryRepository $history,
    ) {
    }

    /**
     * One entry point for public pages.
     *
     * @return array<string, mixed>|null
     */
    public function resolvePublicTenant(?string $host, ?string $identifier): ?array
    {
        $host = $this->normalizeHost($host);

        if ($host !== '') {
            $tenant = $this->tenants->tenantForActiveDomain($host);

            if ($tenant !== null) {
                return $tenant;
            }
        }

        $identifier = $identifier === null ? '' : trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $tenant = $this->tenants->findBySlug(strtolower($identifier));

        if ($tenant !== null && (string) $tenant['public_form_mode'] === 'slug') {
            return $tenant;
        }

        $tenant = $this->tenants->findByPublicKey(strtolower($identifier));

        if ($tenant !== null && (string) $tenant['public_form_mode'] === 'private') {
            return $tenant;
        }

        return null;
    }

    /**
     * A previous slug redirects only while the workspace still uses slug mode.
     *
     * @return array<string, mixed>|null
     */
    public function historyTenant(?string $identifier): ?array
    {
        $identifier = $identifier === null ? '' : strtolower(trim($identifier));

        if ($identifier === '') {
            return null;
        }

        $tenant = $this->history->tenantForOldSlug($identifier);

        if ($tenant === null
            || (string) $tenant['public_form_mode'] !== 'slug'
            || (string) $tenant['slug'] === $identifier
        ) {
            return null;
        }

        return $tenant;
    }

    private function normalizeHost(?string $host): string
    {
        $host = strtolower(trim((string) $host));

        if ($host === '') {
            return '';
        }

        if (str_contains($host, ':')) {
            $host = (string) strstr($host, ':', true);
        }

        return rtrim($host, '.');
    }
}
