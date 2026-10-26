<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        protected string $content = '',
        protected int $status = 200,
        protected array $headers = [],
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function html(string $content, int $status = 200, array $headers = []): self
    {
        $response = new self($content, $status, $headers);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $content = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $response = new self($content, $status, $headers);

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function withHeader(string $name, string $value): self
    {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($this->headers[$existing]);
            }
        }

        $this->headers[$name] = $value;

        return $this;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $this->content;
    }
}
