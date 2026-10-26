<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\Request;
use App\Core\Response;
use App\Services\PublicAccess\PublicTenantGate;
use App\Support\Translator;

abstract class PublicPageController
{
    public function __construct(
        protected readonly PublicTenantGate $gate,
        protected readonly Translator $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function page(string $template, array $data, int $status = 200): Response
    {
        return Response::html(
            app('view')->render($template, array_merge(['locale' => $this->translator->locale()], $data)),
            $status,
        );
    }

    protected function unavailable(Request $request, ?string $workspaceName = null): Response
    {
        return $this->page('public/unavailable.twig', [
            'workspace_name' => $workspaceName ?? (string) config('app.name'),
            'language_urls' => $this->languageUrls($request),
        ], 403);
    }

    /**
     * @return array{en: string, sw: string}
     */
    protected function languageUrls(Request $request): array
    {
        $path = $request->path();

        return [
            'en' => $path . '?lang=en',
            'sw' => $path . '?lang=sw',
        ];
    }
}
