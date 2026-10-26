<?php

declare(strict_types=1);

namespace App\Support;

use App\Repositories\TenantRepository;

final class PublicUrl
{
    public function __construct(private readonly TenantRepository $tenants)
    {
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public function requestForm(array $tenant): string
    {
        $base = $this->base($tenant);

        if ($this->customDomain($tenant) !== null) {
            return $base . '/request';
        }

        $identifier = (string) $tenant['public_form_mode'] === 'private'
            ? (string) $tenant['public_key']
            : (string) $tenant['slug'];

        return $base . '/request/' . rawurlencode($identifier);
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public function track(array $tenant, string $rawToken): string
    {
        return $this->base($tenant) . '/track/' . rawurlencode($rawToken);
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public function staffTicket(array $tenant, string $reference): string
    {
        return rtrim((string) config('app.url', ''), '/') . '/app/tickets/' . rawurlencode($reference);
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public function base(array $tenant): string
    {
        $domain = $this->customDomain($tenant);

        if ($domain !== null) {
            return 'https://' . $domain;
        }

        return rtrim((string) config('app.url', ''), '/');
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function customDomain(array $tenant): ?string
    {
        $domain = $this->tenants->activeDomainForTenant((int) ($tenant['id'] ?? 0));

        return $domain === null ? null : (string) $domain['domain'];
    }
}
