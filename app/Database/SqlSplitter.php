<?php

declare(strict_types=1);

namespace App\Database;

final class SqlSplitter
{
    /**
     * Split a SQL file into individual statements while respecting quotes and comments.
     *
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                $current .= $char;

                if ($char === "\n") {
                    $inLineComment = false;
                }

                continue;
            }

            if ($inBlockComment) {
                $current .= $char;

                if ($char === '*' && $next === '/') {
                    $current .= $next;
                    $i++;
                    $inBlockComment = false;
                }

                continue;
            }

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $next !== '') {
                    $current .= $next;
                    $i++;
                    continue;
                }

                if ($char === $quote) {
                    if ($next === $quote) {
                        $current .= $next;
                        $i++;
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === '-' && $next === '-') {
                $after = $i + 2 < $length ? $sql[$i + 2] : '';

                if ($after === '' || ctype_space($after)) {
                    $current .= '--';
                    $i++;
                    $inLineComment = true;
                    continue;
                }
            }

            if ($char === '#') {
                $current .= $char;
                $inLineComment = true;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $current .= '/*';
                $i++;
                $inBlockComment = true;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $current .= $char;
                $quote = $char;
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($current);

                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }

                $current = '';
                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);

        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
