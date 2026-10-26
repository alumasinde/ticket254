<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TicketReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TicketReferenceTest extends TestCase
{
    public function test_format_pads_to_six_digits_and_grows_beyond_that(): void
    {
        $this->assertSame('HD-000001', TicketReference::format(1));
        $this->assertSame('HD-000123', TicketReference::format(123));
        $this->assertSame('HD-123456', TicketReference::format(123456));
        $this->assertSame('HD-1234567', TicketReference::format(1234567));
    }

    #[DataProvider('validReferences')]
    public function test_parse_accepts_workspace_references(string $reference, int $expected): void
    {
        $this->assertSame($expected, TicketReference::parse($reference));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function validReferences(): array
    {
        return [
            'padded' => ['HD-000001', 1],
            'unpadded' => ['HD-42', 42],
            'lowercase' => ['hd-42', 42],
            'whitespace' => ['  HD-000007  ', 7],
            'long' => ['HD-123456789012345678', 123456789012345678],
        ];
    }

    #[DataProvider('invalidReferences')]
    public function test_parse_rejects_anything_that_is_not_a_ticket_reference(string $value): void
    {
        $this->assertNull(TicketReference::parse($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidReferences(): array
    {
        return [
            'bare number' => ['1'],
            'database id' => ['42'],
            'missing number' => ['HD-'],
            'letters' => ['HD-ABC'],
            'zero' => ['HD-0'],
            'negative' => ['HD--1'],
            'prefix only' => ['HD'],
            'embedded' => ['xHD-1'],
            'nineteen digits' => ['HD-1234567890123456789'],
        ];
    }

    public function test_format_and_parse_round_trip(): void
    {
        foreach ([1, 9, 10, 999999, 1000000, 4294967295] as $number) {
            $this->assertSame($number, TicketReference::parse(TicketReference::format($number)));
        }
    }
}
