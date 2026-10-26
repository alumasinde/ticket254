<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Application;
use App\Core\ArraySession;
use App\Core\Request;
use App\Core\Response;
use App\Core\SessionInterface;
use App\Database\Database;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Application $app;

    protected ArraySession $session;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Application $app */
        $app = require BASE_PATH . '/bootstrap/app.php';
        $this->app = $app;
        $this->session = new ArraySession();

        $this->app->container()->instance(SessionInterface::class, $this->session);
        $this->app->container()->instance('session', $this->session);

        $this->db()->statement('DELETE FROM rate_limits');
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $uri, array $data = [], array $headers = []): Response
    {
        return $this->app->handle(Request::create($method, $uri, $data, $headers));
    }

    /**
     * @param array<string, string> $headers
     */
    protected function get(string $uri, array $headers = []): Response
    {
        return $this->request('GET', $uri, [], $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function post(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonBody(Response $response): array
    {
        return json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function db(): Database
    {
        return $this->app->container()->get(Database::class);
    }
}
