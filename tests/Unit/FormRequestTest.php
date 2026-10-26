<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ForbiddenException;
use App\Core\FormRequest;
use App\Core\Request;
use App\Core\ValidationException;
use PHPUnit\Framework\TestCase;

final class FormRequestTest extends TestCase
{
    public function test_valid_input_is_exposed_as_validated_data(): void
    {
        $form = new ProbeFormRequest(Request::create('POST', '/probe', [
            'email' => 'james@example.com',
            'note' => 'ignored',
        ]));

        $form->validateRequest();

        $this->assertSame(['email' => 'james@example.com'], $form->validated());
        $this->assertSame([], $form->errors());
    }

    public function test_invalid_input_throws_with_errors(): void
    {
        $form = new ProbeFormRequest(Request::create('POST', '/probe', ['email' => 'bad']));

        try {
            $form->validateRequest();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
    }

    public function test_authorize_denial_throws_forbidden(): void
    {
        $form = new DeniedFormRequest(Request::create('POST', '/probe', ['email' => 'a@b.co']));

        $this->expectException(ForbiddenException::class);

        $form->validateRequest();
    }
}

final class ProbeFormRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => 'required|email'];
    }
}

final class DeniedFormRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => 'required|email'];
    }

    public function authorize(): bool
    {
        return false;
    }
}
