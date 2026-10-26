<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PublicAccess\PublicTenantGate;
use App\Services\PublicAccess\TrackingService;
use App\Support\Translator;

final class TrackingController extends PublicPageController
{
    public function __construct(
        PublicTenantGate $gate,
        Translator $translator,
        private readonly TrackingService $tracking,
    ) {
        parent::__construct($gate, $translator);
    }

    public function show(Request $request, string $token): Response
    {
        try {
            $result = $this->tracking->view($token, $request->ip());
        } catch (HttpException $exception) {
            if ($exception->status === 403) {
                return $this->unavailable($request, (string) ($exception->context['workspace_name'] ?? ''));
            }

            throw $exception;
        }

        return $this->page('public/track.twig', [
            'workspace_name' => (string) $result['tenant']['name'],
            'ticket' => $result['data'],
            'token' => $token,
            'form_action' => '/track/' . rawurlencode($token),
            'language_urls' => $this->languageUrls($request),
            'errors' => session('errors') ?? [],
        ]);
    }

    public function reply(Request $request, string $token): Response
    {
        try {
            $this->tracking->reply($token, (string) $request->input('body', ''), $request->ip());
        } catch (HttpException $exception) {
            if ($exception->status === 403) {
                return $this->unavailable($request, (string) ($exception->context['workspace_name'] ?? ''));
            }

            throw $exception;
        }

        return redirect('/track/' . rawurlencode($token))->with('success', t('public.track.reply_sent'));
    }
}
