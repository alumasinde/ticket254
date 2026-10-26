<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function test_reserved_words_are_matched_case_insensitively(): void
    {
        foreach ([
            'app',
            'api',
            'admin',
            'platform',
            'login',
            'register',
            'track',
            'request',
            'invite',
            'www',
            'support',
            'help',
            'static',
            'assets',
        ] as $slug) {
            $this->assertTrue(Slug::isReserved($slug), $slug . ' should be reserved.');
        }

        $this->assertTrue(Slug::isReserved('Admin'));
        $this->assertTrue(Slug::isReserved('API'));
        $this->assertTrue(Slug::isReserved('Support'));
    }

    public function test_ordinary_workspace_slugs_are_available(): void
    {
        foreach (['gleehotel', 'savannah-lodge', 'administration', 'app-2', 'my-support-desk', ''] as $slug) {
            $this->assertFalse(Slug::isReserved($slug), $slug . ' should be available.');
        }
    }
}
