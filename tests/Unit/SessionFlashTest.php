<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ArraySession;
use PHPUnit\Framework\TestCase;

final class SessionFlashTest extends TestCase
{
    public function test_flash_survives_one_request(): void
    {
        $session = new ArraySession();
        $session->flash('notice', 'Saved.');

        $session->beginRequest();
        $this->assertSame('Saved.', $session->get('notice'));

        $session->beginRequest();
        $this->assertNull($session->get('notice'));
    }

    public function test_pull_removes_a_value(): void
    {
        $session = new ArraySession();
        $session->put('key', 'value');

        $this->assertSame('value', $session->pull('key'));
        $this->assertFalse($session->has('key'));
    }

    public function test_destroy_clears_data(): void
    {
        $session = new ArraySession();
        $session->put('key', 'value');
        $session->destroy();

        $this->assertNull($session->get('key'));
    }
}
