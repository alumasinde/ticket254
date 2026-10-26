<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TenantRepository;
use App\Services\Tenants\PublicSettings;
use App\Services\Tenants\TenantContext;

final class SettingsController
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly PublicSettings $settings,
    ) {
    }

    public function show(TenantContext $context): Response
    {
        return Response::json(['data' => $this->settings->view($this->tenant($context))]);
    }

    public function update(Request $request, TenantContext $context): Response
    {
        $input = $request->all();
        $tenant = $this->tenant($context);
        $current = $this->settings->view($tenant);
        $profileKeys = ['name', 'timezone', 'contact_email', 'contact_phone'];

        if (array_intersect($profileKeys, array_keys($input)) !== []) {
            $this->settings->updateProfile($tenant, array_merge([
                'name' => $current['name'],
                'timezone' => $current['timezone'],
                'contact_email' => $current['contact_email'],
                'contact_phone' => $current['contact_phone'],
            ], $request->only($profileKeys)));

            $tenant = $this->tenant($context);
        }

        if ($this->hasPublicFormKeys($input)) {
            $this->settings->updatePublicForm($tenant, $input);

            $tenant = $this->tenant($context);
        }

        return Response::json(['data' => $this->settings->view($tenant)]);
    }

    public function updatePublicForm(Request $request, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->settings->updatePublicForm($this->tenant($context), $request->all()),
        ]);
    }

    public function regenerate(TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->settings->regenerateKey($this->tenant($context)),
        ]);
    }

    public function updateSlug(Request $request, TenantContext $context): Response
    {
        return Response::json([
            'data' => $this->settings->changeSlug($this->tenant($context), (string) $request->input('slug', '')),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function hasPublicFormKeys(array $input): bool
    {
        return array_intersect(
            ['mode', 'enabled', 'allowed_email_domains', 'allow_requester_replies', 'attachments_enabled'],
            array_keys($input),
        ) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenant(TenantContext $context): array
    {
        $tenant = $this->tenants->findById($context->tenantId);

        if ($tenant === null) {
            throw new NotFoundException();
        }

        return $tenant;
    }
}
