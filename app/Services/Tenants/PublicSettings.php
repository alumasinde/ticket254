<?php

declare(strict_types=1);

namespace App\Services\Tenants;

use App\Core\ValidationException;
use App\Repositories\SlugHistoryRepository;
use App\Repositories\TenantRepository;
use App\Support\PublicUrl;
use App\Support\Slug;
use RuntimeException;

final class PublicSettings
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly SlugHistoryRepository $history,
        private readonly TenantSettings $settings,
        private readonly PublicUrl $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    public function view(array $tenant): array
    {
        $settings = $this->settings->all($tenant);
        $base = $this->urls->base($tenant);

        return [
            'name' => (string) $tenant['name'],
            'timezone' => (string) $tenant['timezone'],
            'contact_email' => $tenant['contact_email'] === null ? null : (string) $tenant['contact_email'],
            'contact_phone' => $tenant['contact_phone'] === null ? null : (string) $tenant['contact_phone'],
            'slug' => (string) $tenant['slug'],
            'public_form_enabled' => (int) $tenant['public_form_enabled'] === 1,
            'public_form_mode' => (string) $tenant['public_form_mode'],
            'public_form_url' => $this->urls->requestForm($tenant),
            'private_form_url' => $base . '/request/' . rawurlencode((string) $tenant['public_key']),
            'allowed_email_domains' => $settings['allowed_email_domains'],
            'allow_requester_replies' => $settings['allow_requester_replies'],
            'attachments_enabled' => $settings['attachments_enabled'],
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updatePublicForm(array $tenant, array $input): array
    {
        $mode = (string) ($input['mode'] ?? $tenant['public_form_mode']);

        if (!in_array($mode, ['slug', 'private'], true)) {
            throw new ValidationException(['mode' => 'Choose the readable slug or the private link.']);
        }

        $settings = $this->rawSettings($tenant);
        $current = $this->settings->all($tenant);
        $domains = array_key_exists('allowed_email_domains', $input)
            ? $this->domains($input['allowed_email_domains'])
            : $current['allowed_email_domains'];

        $settings['allowed_email_domains'] = $domains;
        $settings['allow_requester_replies'] = array_key_exists('allow_requester_replies', $input)
            ? $this->bool($input['allow_requester_replies'])
            : $current['allow_requester_replies'];
        $settings['attachments_enabled'] = array_key_exists('attachments_enabled', $input)
            ? $this->bool($input['attachments_enabled'])
            : $current['attachments_enabled'];

        $enabled = array_key_exists('enabled', $input)
            ? $this->bool($input['enabled'])
            : (int) $tenant['public_form_enabled'] === 1;

        $this->tenants->update((int) $tenant['id'], [
            'public_form_mode' => $mode,
            'public_form_enabled' => $enabled ? 1 : 0,
            'settings_json' => $this->json($settings),
        ]);

        return $this->view($this->reload((int) $tenant['id']));
    }

    /**
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateProfile(array $tenant, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $timezone = trim((string) ($input['timezone'] ?? ''));

        if ($name === '') {
            throw new ValidationException(['name' => 'Enter the workspace name.']);
        }

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new ValidationException(['timezone' => 'Choose a valid timezone.']);
        }

        $email = strtolower(trim((string) ($input['contact_email'] ?? '')));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(['contact_email' => 'Enter a valid contact email.']);
        }

        $this->tenants->update((int) $tenant['id'], [
            'name' => $name,
            'timezone' => $timezone,
            'contact_email' => $email === '' ? null : $email,
            'contact_phone' => $this->nullable((string) ($input['contact_phone'] ?? '')),
        ]);

        return $this->view($this->reload((int) $tenant['id']));
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    public function changeSlug(array $tenant, string $slug): array
    {
        $slug = strtolower(trim($slug));
        $error = $this->slugError($slug, (int) $tenant['id']);

        if ($error !== null) {
            throw new ValidationException(['slug' => $error]);
        }

        if ($slug !== (string) $tenant['slug']) {
            $this->history->record((int) $tenant['id'], (string) $tenant['slug']);
            $this->tenants->update((int) $tenant['id'], ['slug' => $slug]);
        }

        return $this->view($this->reload((int) $tenant['id']));
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    public function regenerateKey(array $tenant): array
    {
        $this->tenants->update((int) $tenant['id'], [
            'public_key' => bin2hex(random_bytes(16)),
        ]);

        return $this->view($this->reload((int) $tenant['id']));
    }

    public function slugAvailable(string $slug): bool
    {
        $slug = strtolower(trim($slug));

        return $this->slugError($slug) === null;
    }

    private function slugError(string $slug, ?int $currentTenantId = null): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug) !== 1) {
            return 'Use 3–40 lowercase letters, numbers or hyphens, without starting or ending with a hyphen.';
        }

        if (str_contains($slug, '--')) {
            return 'Use single hyphens only.';
        }

        if (Slug::isReserved($slug)) {
            return 'That workspace address is reserved.';
        }

        $existing = $this->tenants->findBySlug($slug);

        if ($existing !== null && (int) $existing['id'] !== $currentTenantId) {
            return 'That workspace address is already taken.';
        }

        return null;
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

            $domain = strtolower(trim(ltrim(trim($domain), '@')));

            if ($domain === '') {
                continue;
            }

            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) !== 1) {
                throw new ValidationException([
                    'allowed_email_domains' => 'Use domain names such as gleehotel.co.ke, separated by commas.',
                ]);
            }

            $domains[] = $domain;
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    private function rawSettings(array $tenant): array
    {
        $raw = $tenant['settings_json'] ?? null;
        $settings = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($settings) ? $settings : [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function json(array $settings): string
    {
        return json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function reload(int $tenantId): array
    {
        $tenant = $this->tenants->findById($tenantId);

        if ($tenant === null) {
            throw new RuntimeException('The workspace could not be reloaded.');
        }

        return $tenant;
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
