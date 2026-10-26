<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TranslationsTest extends TestCase
{
    public function test_english_and_kiswahili_have_the_same_keys_and_placeholders(): void
    {
        $english = require BASE_PATH . '/resources/lang/en.php';
        $swahili = require BASE_PATH . '/resources/lang/sw.php';

        $this->assertIsArray($english);
        $this->assertIsArray($swahili);

        $englishKeys = array_keys($english);
        $swahiliKeys = array_keys($swahili);
        sort($englishKeys);
        sort($swahiliKeys);

        $this->assertSame($englishKeys, $swahiliKeys);

        foreach ($english as $key => $line) {
            $this->assertIsString($line, $key);
            $this->assertNotSame('', trim($line), $key);
            $this->assertIsString($swahili[$key], $key);
            $this->assertNotSame('', trim($swahili[$key]), $key);
            $this->assertSame($this->placeholders($line), $this->placeholders($swahili[$key]), $key);
        }
    }

    public function test_kiswahili_is_actually_translated(): void
    {
        $english = require BASE_PATH . '/resources/lang/en.php';
        $swahili = require BASE_PATH . '/resources/lang/sw.php';

        $different = 0;

        foreach ($english as $key => $line) {
            if ($swahili[$key] !== $line) {
                $different++;
            }
        }

        $this->assertGreaterThanOrEqual(15, $different);
        $this->assertSame('Omba msaada', $swahili['public.request.title']);
        $this->assertSame('Tuma ombi', $swahili['public.request.submit']);
        $this->assertSame('Hali', $swahili['public.track.status']);
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $line): array
    {
        preg_match_all('/:([a-z_]+)/', $line, $matches);

        $placeholders = $matches[1] ?? [];
        sort($placeholders);

        return $placeholders;
    }
}
