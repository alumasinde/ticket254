<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Request;
use App\Core\Response;

trait SendsWorkspaceRequests
{
    /**
     * @param array<string, mixed> $data
     */
    protected function webPost(string $uri, array $data = []): Response
    {
        return $this->post($uri, $data + ['_token' => csrf_token()]);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function webPatch(string $uri, array $data = []): Response
    {
        return $this->post($uri, $data + ['_method' => 'PATCH', '_token' => csrf_token()]);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function webDelete(string $uri, array $data = []): Response
    {
        return $this->post($uri, $data + ['_method' => 'DELETE', '_token' => csrf_token()]);
    }

    /**
     * @param array<string, mixed> $files
     * @param array<string, mixed> $data
     */
    protected function webUpload(string $uri, array $files, array $data = []): Response
    {
        return $this->app->handle(Request::create(
            'POST',
            $uri,
            $data + ['_token' => csrf_token()],
            ['Referer' => rtrim((string) config('app.url', 'http://localhost'), '/')],
            [],
            $files,
        ));
    }

    /**
     * @param array<string, mixed> $files
     * @param array<string, mixed> $data
     */
    protected function apiUpload(string $uri, array $files, array $data = [], string $method = 'POST'): Response
    {
        return $this->app->handle(Request::create(
            $method,
            $uri,
            $data,
            ['Accept' => 'application/json', 'X-CSRF-Token' => csrf_token()],
            [],
            $files,
        ));
    }

    protected function apiDelete(string $uri): Response
    {
        return $this->request('DELETE', $uri, [], [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);
    }
}
