<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Tickets\TicketFilters;
use PHPUnit\Framework\TestCase;

final class TicketFiltersTest extends TestCase
{
    public function test_defaults_show_the_newest_tickets_on_the_first_page(): void
    {
        $filters = TicketFilters::fromQuery([]);

        $this->assertNull($filters->q);
        $this->assertNull($filters->status);
        $this->assertNull($filters->teamId);
        $this->assertFalse($filters->overdue);
        $this->assertFalse($filters->unassigned);
        $this->assertFalse($filters->mine);
        $this->assertSame('-created_at', $filters->sort);
        $this->assertSame(1, $filters->page);
        $this->assertSame(25, $filters->perPage);
    }

    public function test_query_values_are_trimmed_and_normalized(): void
    {
        $filters = TicketFilters::fromQuery([
            'q' => '  printer  ',
            'status' => 'open',
            'priority' => 'high',
            'team_id' => '12',
            'category_id' => '3',
            'site_id' => '4',
            'assigned_user_id' => '9',
            'created_from' => '2026-10-01',
            'created_to' => '2026-10-26',
            'overdue' => '1',
            'unassigned' => 'true',
            'mine' => 'on',
            'sort' => 'due_at',
            'page' => '3',
            'per_page' => '50',
        ]);

        $this->assertSame('printer', $filters->q);
        $this->assertSame('open', $filters->status);
        $this->assertSame(12, $filters->teamId);
        $this->assertSame(3, $filters->categoryId);
        $this->assertSame(4, $filters->siteId);
        $this->assertSame(9, $filters->assignedUserId);
        $this->assertSame('2026-10-01', $filters->createdFrom);
        $this->assertSame('2026-10-26', $filters->createdTo);
        $this->assertTrue($filters->overdue);
        $this->assertTrue($filters->unassigned);
        $this->assertTrue($filters->mine);
        $this->assertSame('due_at', $filters->sort);
        $this->assertSame(3, $filters->page);
        $this->assertSame(50, $filters->perPage);
    }

    public function test_page_size_and_page_number_are_clamped(): void
    {
        $this->assertSame(100, TicketFilters::fromQuery(['per_page' => '1000'])->perPage);
        $this->assertSame(1, TicketFilters::fromQuery(['per_page' => '0'])->perPage);
        $this->assertSame(1, TicketFilters::fromQuery(['page' => '0'])->page);
        $this->assertSame(1, TicketFilters::fromQuery(['page' => '-4'])->page);
    }

    public function test_unknown_sort_falls_back_to_newest_first(): void
    {
        $this->assertSame('-created_at', TicketFilters::fromQuery(['sort' => 'drop table'])->sort);
        $this->assertSame('-priority', TicketFilters::fromQuery(['sort' => '-priority'])->sort);
    }

    public function test_non_scalar_and_malformed_values_are_ignored(): void
    {
        $filters = TicketFilters::fromQuery([
            'q' => ['printer'],
            'team_id' => ['1'],
            'category_id' => '1 OR 1=1',
            'mine' => ['1'],
            'page' => ['2'],
        ]);

        $this->assertNull($filters->q);
        $this->assertNull($filters->teamId);
        $this->assertNull($filters->categoryId);
        $this->assertFalse($filters->mine);
        $this->assertSame(1, $filters->page);
    }

    public function test_only_truthy_flags_are_enabled(): void
    {
        foreach (['1', 'true', 'TRUE', 'on', 'yes'] as $value) {
            $this->assertTrue(TicketFilters::fromQuery(['overdue' => $value])->overdue, $value);
        }

        foreach (['0', 'false', 'no', '', 'anything'] as $value) {
            $this->assertFalse(TicketFilters::fromQuery(['overdue' => $value])->overdue, $value);
        }
    }

    public function test_pagination_query_drops_defaults_and_keeps_active_filters(): void
    {
        $default = TicketFilters::fromQuery([]);
        $this->assertSame([], $default->toQuery());

        $filters = TicketFilters::fromQuery([
            'q' => 'noise',
            'status' => 'open',
            'mine' => '1',
            'sort' => 'due_at',
            'page' => '2',
            'per_page' => '10',
        ]);

        $query = $filters->toQuery();

        $this->assertSame('noise', $query['q']);
        $this->assertSame('open', $query['status']);
        $this->assertTrue($query['mine']);
        $this->assertSame('due_at', $query['sort']);
        $this->assertSame(10, $query['per_page']);
        $this->assertArrayNotHasKey('page', $query);
        $this->assertArrayNotHasKey('unassigned', $query);
    }
}
