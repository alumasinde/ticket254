<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\QrCode;
use PHPUnit\Framework\TestCase;

final class QrCodeTest extends TestCase
{
    public function test_svg_and_data_uri_are_generated_for_a_public_link(): void
    {
        $qr = new QrCode();
        $url = 'https://glee.example/request/glee-hotel';

        $svg = $qr->svg($url, 4);
        $this->assertStringContainsString('<svg', $svg);

        $dataUri = $qr->dataUri($url, 4);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);

        $decoded = base64_decode(substr($dataUri, strlen('data:image/svg+xml;base64,')), true);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('<svg', $decoded);
        $this->assertNotSame($qr->svg($url, 4), $qr->svg($url . '/other', 4));
    }
}
