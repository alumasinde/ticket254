<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\FormRequest;
use App\Core\Response;
use App\Core\Router;
use Tests\TestCase;

final class ValidationFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app->container()->get(Router::class);
        $router->post('/probe', static function (ProbeRequest $form): Response {
            return Response::json(['data' => $form->validated()]);
        });
    }

    public function test_invalid_html_submission_redirects_with_errors_and_old_input(): void
    {
        $response = $this->post('/probe', [
            'email' => 'not-an-email',
            'password' => 'should-not-be-kept',
            '_token' => 'token-value',
        ]);

        $this->assertSame(302, $response->status());
        $this->assertSame('/', $response->header('Location'));

        $errors = $this->session->get('errors');
        $this->assertArrayHasKey('email', $errors);

        $old = $this->session->get('_old_input');
        $this->assertSame('not-an-email', $old['email']);
        $this->assertArrayNotHasKey('password', $old);
        $this->assertArrayNotHasKey('_token', $old);
    }

    public function test_invalid_json_submission_returns_422_envelope(): void
    {
        $response = $this->post('/probe', ['email' => 'not-an-email'], ['Accept' => 'application/json']);

        $this->assertSame(422, $response->status());
        $body = $this->jsonBody($response);
        $this->assertSame('validation_failed', $body['error']['code']);
        $this->assertSame('Please correct the highlighted fields.', $body['error']['message']);
        $this->assertArrayHasKey('email', $body['error']['fields']);
    }

    public function test_valid_json_submission_returns_validated_data(): void
    {
        $response = $this->post('/probe', ['email' => 'james@example.com'], ['Accept' => 'application/json']);

        $this->assertSame(200, $response->status());
        $body = $this->jsonBody($response);
        $this->assertSame(['email' => 'james@example.com'], $body['data']);
    }

    public function test_redirect_target_uses_the_referer_path_only(): void
    {
        $response = $this->post(
            '/probe',
            ['email' => 'not-an-email'],
            ['Referer' => 'http://localhost/app/settings?tab=profile'],
        );

        $this->assertSame('/app/settings', $response->header('Location'));
    }

    public function test_flash_errors_are_visible_for_one_following_request(): void
    {
        $this->post('/probe', ['email' => 'not-an-email']);
        $this->assertArrayHasKey('email', $this->session->get('errors'));

        $this->get('/');
        $this->assertArrayHasKey('email', $this->session->get('errors'));

        $this->get('/');
        $this->assertNull($this->session->get('errors'));
    }
}

final class ProbeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
        ];
    }
}
