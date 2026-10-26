<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\Response;
use App\Core\Router;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

final class ErrorHandlingTest extends TestCase
{
    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app->container()->get(Router::class);
        $router->get('/boom', static function (): Response {
            throw new RuntimeException('top secret detail');
        });

        $this->logs = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($this->logs);
        $this->app->container()->instance(LoggerInterface::class, $logger);
        $this->app->container()->instance('logger', $logger);
    }

    public function test_json_500_hides_details_and_logs_them(): void
    {
        $response = $this->get('/boom', ['Accept' => 'application/json']);

        $this->assertSame(500, $response->status());
        $this->assertStringNotContainsString('top secret detail', $response->content());

        $body = $this->jsonBody($response);
        $this->assertSame('server_error', $body['error']['code']);
        $this->assertSame('Something went wrong.', $body['error']['message']);

        $errorId = $response->header('X-Error-Id');
        $this->assertNotNull($errorId);

        $logged = false;

        foreach ($this->logs->getRecords() as $record) {
            if ($record->message !== 'Unhandled exception') {
                continue;
            }

            $logged = true;
            $this->assertSame($errorId, $record->context['error_id']);
            $this->assertInstanceOf(RuntimeException::class, $record->context['exception']);
            $this->assertSame('top secret detail', $record->context['exception']->getMessage());
        }

        $this->assertTrue($logged, 'The exception should be logged.');
    }

    public function test_html_500_reveals_details_only_in_debug_mode(): void
    {
        $config = $this->app->container()->get(Config::class);

        $config->set('app.debug', true);
        $debugResponse = $this->get('/boom');
        $this->assertSame(500, $debugResponse->status());
        $this->assertStringContainsString('top secret detail', $debugResponse->content());

        $config->set('app.debug', false);
        $productionResponse = $this->get('/boom');
        $this->assertSame(500, $productionResponse->status());
        $this->assertStringNotContainsString('top secret detail', $productionResponse->content());
        $this->assertStringContainsString('Something went wrong', $productionResponse->content());
        $this->assertNotNull($productionResponse->header('X-Error-Id'));
    }
}
