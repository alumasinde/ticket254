<?php

declare(strict_types=1);

namespace App\Services\Tenants;

final class TenantSettings
{
    /**
     * @param array<string, mixed> $tenant
     * @return array{allowed_email_domains: list<string>, allow_requester_replies: bool, attachments_enabled: bool}
     */
    public function all(array $tenant): array
    {
        $raw = $tenant['settings_json'] ?? null;
        $settings = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($settings)) {
            $settings = [];
        }

        return [
            'allowed_email_domains' => $this->domains($settings['allowed_email_domains'] ?? []),
            'allow_requester_replies' => $this->bool($settings['allow_requester_replies'] ?? true),
            'attachments_enabled' => $this->bool($settings['attachments_enabled'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public function allowsEmailDomain(array $tenant, string $email): bool
    {
        $domains = $this->all($tenant)['allowed_email_domains'];

        if ($domains === []) {
            return true;
        }

        $at = strrpos($email, '@');
        $domain = $at === false ? '' : strtolower(substr($email, $at + 1));

        if ($domain === '') {
            return false;
        }

        foreach ($domains as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public function allowsReplies(array $tenant): bool
    {
        return $this->all($tenant)['allow_requester_replies'];
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public function attachmentsEnabled(array $tenant): bool
    {
        return $this->all($tenant)['attachments_enabled'];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function domains(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $domains = [];

        foreach ($value as $domain) {
            if (!is_string($domain)) {
                continue;
            }

            $domain = strtolower(trim($domain));
            $domain = ltrim($domain, '@');

            if ($domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) === 1) {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }
}
