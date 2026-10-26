<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ForbiddenException;

abstract class FormRequest
{
    /** @var array<string, mixed> */
    private array $validated = [];

    /** @var array<string, string> */
    private array $errors = [];

    private bool $validatedOnce = false;

    public function __construct(protected readonly Request $request)
    {
    }

    /**
     * @return array<string, string|list<string>>
     */
    abstract public function rules(): array;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    public function validateRequest(): void
    {
        if (!$this->authorize()) {
            throw new ForbiddenException();
        }

        $validator = new Validator();

        try {
            $this->validated = $validator->validate($this->request->all(), $this->rules(), $this->messages());
        } catch (ValidationException $exception) {
            $this->errors = $exception->errors();

            throw $exception;
        }

        $this->validatedOnce = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        if (!$this->validatedOnce) {
            $this->validateRequest();
        }

        return $this->validated;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request->input($key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->request->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        return $this->request->file($key);
    }

    public function request(): Request
    {
        return $this->request;
    }
}
