<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Request;
use App\Core\Response;

trait BuildsPublicAccess
{
    /** @var list<string> */
    protected array $tempUploads = [];

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function publicPayload(array $overrides = []): array
    {
        return array_replace([
            'requester_name' => 'Njeri Wambui',
            'requester_email' => 'njeri.wambui@example.co.ke',
            'requester_phone' => '+254700000001',
            'subject' => 'No hot water in room 204',
            'description' => 'The guest reports cold water since 06:00. Please check the boiler.',
        ], $overrides);
    }

    protected function publicConfiguration(string $identifier): Response
    {
        return $this->get(
            '/api/v1/public/workspaces/' . rawurlencode($identifier),
            ['Accept' => 'application/json'],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $files
     */
    protected function submitPublicRequest(string $identifier, array $overrides = [], array $files = []): Response
    {
        return $this->app->handle(Request::create(
            'POST',
            '/api/v1/public/workspaces/' . rawurlencode($identifier) . '/tickets',
            $this->publicPayload($overrides),
            ['Accept' => 'application/json'],
            [],
            $files,
        ));
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    protected function createPublicRequest(string $identifier, array $overrides = [], array $files = []): array
    {
        $response = $this->submitPublicRequest($identifier, $overrides, $files);

        $this->assertSame(201, $response->status(), $response->content());

        return $this->jsonBody($response)['data'];
    }

    protected function trackViaApi(string $token): Response
    {
        return $this->get('/api/v1/track/' . rawurlencode($token), ['Accept' => 'application/json']);
    }

    protected function replyViaApi(string $token, string $body): Response
    {
        return $this->post(
            '/api/v1/track/' . rawurlencode($token) . '/replies',
            ['body' => $body],
            ['Accept' => 'application/json'],
        );
    }

    protected function publicFormPage(?string $identifier = null, string $query = ''): Response
    {
        $path = $identifier === null ? '/request' : '/request/' . rawurlencode($identifier);

        return $this->get($path . $query);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $files
     */
    protected function submitPublicForm(string $identifier, array $overrides = [], array $files = []): Response
    {
        return $this->app->handle(Request::create(
            'POST',
            '/request/' . rawurlencode($identifier),
            $this->publicPayload($overrides) + ['_token' => csrf_token()],
            ['Referer' => '/request/' . rawurlencode($identifier)],
            [],
            $files,
        ));
    }

    protected function trackingPage(string $token): Response
    {
        return $this->get('/track/' . rawurlencode($token));
    }

    protected function replyOnTrackingPage(string $token, string $body): Response
    {
        return $this->post(
            '/track/' . rawurlencode($token),
            ['body' => $body, '_token' => csrf_token()],
        );
    }

    protected function tokenFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        $this->assertMatchesRegularExpression('#/track/[0-9a-f]{64}$#', $path);

        return (string) basename($path);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function apiGet(string $uri): Response
    {
        return $this->get($uri, ['Accept' => 'application/json']);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function apiPatch(string $uri, array $data): Response
    {
        return $this->request('PATCH', $uri, $data, [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function apiPost(string $uri, array $data = []): Response
    {
        return $this->post($uri, $data, [
            'Accept' => 'application/json',
            'X-CSRF-Token' => csrf_token(),
        ]);
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    protected function upload(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'hd_upload_');

        if ($path === false) {
            $this->fail('Could not create a temporary upload file.');
        }

        file_put_contents($path, $contents);
        $this->tempUploads[] = $path;

        return [
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
        ];
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    protected function pngUpload(string $name = 'reception.png'): array
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            true,
        );

        $this->assertIsString($png);

        return $this->upload($name, $png);
    }

    protected function cleanupTempUploads(): void
    {
        foreach ($this->tempUploads as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempUploads = [];
    }

    protected function deleteTenantStorage(int $tenantId): void
    {
        $directory = base_path('storage/private/' . $tenantId);

        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
