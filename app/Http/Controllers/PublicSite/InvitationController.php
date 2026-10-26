<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\SessionAuth;
use App\Services\PublicAccess\PublicTenantGate;
use App\Services\Workspace\InvitationService;
use App\Support\Translator;

final class InvitationController extends PublicPageController
{
    public function __construct(
        PublicTenantGate $gate,
        Translator $translator,
        private readonly InvitationService $invitations,
        private readonly SessionAuth $auth,
    ) {
        parent::__construct($gate, $translator);
    }

    public function show(string $token): Response
    {
        $preview = $this->invitations->preview($token, $this->auth->userId());

        if ($preview === null) {
            throw new HttpException(410, 'This invitation is invalid or has expired.', [], 'invitation_invalid');
        }

        return $this->page('public/invite.twig', [
            'workspace_name' => $preview['workspace_name'],
            'language_urls' => [],
            'invitation' => $preview,
            'errors' => session('errors') ?? [],
        ]);
    }

    public function accept(Request $request, string $token): Response
    {
        $result = $this->invitations->accept($token, $request->all(), $request);

        return redirect('/app')->with(
            'success',
            'Welcome to ' . $result['tenant_name'] . '.',
        );
    }
}
