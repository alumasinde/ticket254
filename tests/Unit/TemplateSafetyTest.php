<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\TwigFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TemplateSafetyTest extends TestCase
{
    /** @var list<string> */
    private array $templates = [];

    protected function setUp(): void
    {
        $views = BASE_PATH . '/resources/views';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $path = $file->getPathname();
            $this->templates[] = str_replace('\\', '/', substr($path, strlen($views) + 1));
        }

        sort($this->templates);
    }

    public function test_templates_do_not_use_the_raw_filter(): void
    {
        foreach ($this->templates as $template) {
            $source = (string) file_get_contents(BASE_PATH . '/resources/views/' . $template);

            $this->assertStringNotContainsString('|raw', $source, "Template [$template] uses |raw.");
        }
    }

    public function test_every_template_compiles(): void
    {
        $twig = new TwigFactory(
            BASE_PATH . '/resources/views',
            BASE_PATH . '/storage/cache/twig',
            true,
        );

        $this->assertNotSame([], $this->templates);

        foreach ($this->templates as $template) {
            $compiled = $twig->environment()->load($template);

            $this->assertNotNull($compiled, "Template [$template] did not compile.");
        }
    }
}
