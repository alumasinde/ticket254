<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Tenants\PublicSettings;
use App\Services\Tenants\TenantContext;
use App\Support\QrCode;

final class SettingsController extends WorkspaceController
{
    public function __construct(
        UserRepository $users,
        TenantRepository $tenants,
        private readonly PublicSettings $settings,
        private readonly QrCode $qr,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($users, $tenants);
    }

    public function show(TenantContext $context): Response
    {
        $settings = $this->settings->view($this->tenant($context));
        $share = sprintf('Request support from %s: %s', $settings['name'], $settings['public_form_url']);

        return $this->page($context, 'app/settings.twig', [
            'settings' => $settings,
            'timezones' => timezone_identifiers_list(),
            'qr' => $this->qr->dataUri($settings['public_form_url'], 4),
            'private_qr' => $this->qr->dataUri($settings['private_form_url'], 4),
            'whatsapp' => 'https://wa.me/?text=' . rawurlencode($share),
            'errors' => session('errors') ?? [],
        ], 'settings');
    }

    public function updateProfile(Request $request, TenantContext $context): Response
    {
        $this->settings->updateProfile($this->tenant($context), $request->all());
        $this->audit->record($request, $context->tenantId, $context->userId, 'settings.profile_updated', 'tenant', $context->tenantId, [
            'changed' => array_values(array_diff(array_keys($request->all()), ['_token', '_method'])),
        ]);

        return redirect(route('settings.show'))->with('success', 'Workspace details saved.');
    }

    public function updatePublicForm(Request $request, TenantContext $context): Response
    {
        $this->settings->updatePublicForm($this->tenant($context), $request->all());
        $this->audit->record($request, $context->tenantId, $context->userId, 'settings.public_form_updated', 'tenant', $context->tenantId, [
            'changed' => array_values(array_diff(array_keys($request->all()), ['_token', '_method'])),
        ]);

        return redirect(route('settings.show'))->with('success', 'Public request form updated.');
    }

    public function updateSlug(Request $request, TenantContext $context): Response
    {
        $tenant = $this->tenant($context);
        $this->settings->changeSlug($tenant, (string) $request->input('slug', ''));
        $this->audit->record($request, $context->tenantId, $context->userId, 'settings.slug_changed', 'tenant', $context->tenantId, [
            'from' => (string) $tenant['slug'],
            'to' => (string) $request->input('slug', ''),
        ]);

        return redirect(route('settings.show'))->with('success', 'Workspace address updated. Old links redirect here.');
    }

    public function regenerate(Request $request, TenantContext $context): Response
    {
        $this->settings->regenerateKey($this->tenant($context));
        $this->audit->record($request, $context->tenantId, $context->userId, 'settings.key_regenerated', 'tenant', $context->tenantId);

        return redirect(route('settings.show'))->with('success', 'A new private link was generated. The old link no longer works.');
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
