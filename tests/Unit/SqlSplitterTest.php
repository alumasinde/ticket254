<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function test_splits_statements_on_semicolons(): void
    {
        $statements = SqlSplitter::split('CREATE TABLE a (id INT); INSERT INTO a VALUES (1);');

        $this->assertCount(2, $statements);
        $this->assertSame('CREATE TABLE a (id INT)', $statements[0]);
        $this->assertSame('INSERT INTO a VALUES (1)', $statements[1]);
    }

    public function test_ignores_semicolons_inside_quotes_comments_and_escaped_quotes(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE a (t VARCHAR(50));
            -- semicolon in a line comment ;
            INSERT INTO a VALUES ('semi;colon');
            # another ; comment
            INSERT INTO a VALUES ("double;quote");
            /* block ; comment */
            UPDATE a SET t = 'it''s; fine';
            INSERT INTO a VALUES ('it\'s; fine');
            SQL;

        $statements = SqlSplitter::split($sql);

        $this->assertCount(5, $statements);
    }

    public function test_keeps_a_final_statement_without_semicolon(): void
    {
        $statements = SqlSplitter::split('SELECT 1');

        $this->assertSame(['SELECT 1'], $statements);
    }

    public function test_ignores_empty_statements(): void
    {
        $this->assertSame([], SqlSplitter::split(";\n;\n  ;"));
    }
}
