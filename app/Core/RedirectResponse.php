<?php

declare(strict_types=1);

namespace App\Core;

final class RedirectResponse extends Response
{
    public function __construct(
        string $location,
        int $status = 302,
        private readonly ?SessionInterface $session = null,
    ) {
        parent::__construct('', $status, ['Location' => $location]);
    }

    public function with(string $key, mixed $value): self
    {
        $this->session?->flash($key, $value);

        return $this;
    }

    /**
     * @param array<string, string> $errors
     */
    public function withErrors(array $errors): self
    {
        $this->session?->flash('errors', $errors);

        return $this;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function withInput(array $input): self
    {
        unset($input['_method'], $input['_token'], $input['password'], $input['password_confirmation']);

        $this->session?->flash('_old_input', $input);

        return $this;
    }
}
