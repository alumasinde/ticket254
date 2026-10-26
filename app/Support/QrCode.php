<?php

declare(strict_types=1);

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode as QrCodeRenderer;
use chillerlan\QRCode\QROptions;

final class QrCode
{
    public function svg(string $text, int $scale = 5): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => max(2, $scale),
            'svgUseFillAttributes' => true,
        ]);

        return (new QrCodeRenderer($options))->render($text);
    }

    public function dataUri(string $text, int $scale = 5): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($this->svg($text, $scale));
    }
}
