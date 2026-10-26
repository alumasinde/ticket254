<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PublicAccess\PublicRequestService;
use App\Services\PublicAccess\PublicTenantGate;
use App\Support\Translator;

final class RequestController extends PublicPageController
{
    public function __construct(
        PublicTenantGate $gate,
        Translator $translator,
        private readonly PublicRequestService $requests,
    ) {
        parent::__construct($gate, $translator);
    }

    public function show(Request $request, ?string $identifier = null): Response
    {
        try {
            $tenant = $this->gate->resolve($request->header('Host'), $identifier);
        } catch (NotFoundException $exception) {
            $previous = $this->gate->previousSlug($identifier);

            if ($previous !== null) {
                return redirect('/request/' . rawurlencode((string) $previous['slug']), 301);
            }

            throw $exception;
        } catch (HttpException $exception) {
            if ($exception->status === 403) {
                return $this->unavailable($request);
            }

            throw $exception;
        }

        return $this->page('public/request.twig', [
            'workspace_name' => (string) $tenant['name'],
            'config' => $this->requests->configuration($tenant),
            'form_action' => $request->path(),
            'language_urls' => $this->languageUrls($request),
            'errors' => session('errors') ?? [],
            'max_mb' => (int) config('uploads.max_mb', 5),
        ]);
    }

    public function store(Request $request, ?string $identifier = null): Response
    {
        try {
            $tenant = $this->gate->resolve($request->header('Host'), $identifier);
        } catch (HttpException $exception) {
            if ($exception->status === 403) {
                return $this->unavailable($request);
            }

            throw $exception;
        }

        $result = $this->requests->submit(
            $tenant,
            $request->all(),
            $request->file('photo'),
            $request->ip(),
        );

        return redirect('/track/' . rawurlencode($result['tracking_token']))
            ->with('success', t('public.request.received'));
    }
}
