<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager;

use EchoFusion\DatabaseManager\Exceptions\DatabaseException;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_int;
use function ltrim;
use function preg_quote;
use function preg_replace;
use function sprintf;

/**
 * Turns an array bound to a named parameter into a list, so `WHERE id IN (:ids)` works.
 *
 * An empty array becomes `NULL` (so `IN (NULL)` matches nothing). Named parameter keys
 * are returned without the leading colon. Note that a `:name` inside a quoted SQL string
 * is replaced too, so keep literals with colons out of queries that bind arrays.
 *
 * @internal
 */
final class ParameterExpander
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{0: string, 1: array<array-key, mixed>}
     */
    public static function expand(string $sql, array $params): array
    {
        $expanded = [];

        foreach ($params as $key => $value) {
            if (!is_array($value)) {
                $expanded[is_int($key) ? $key : ltrim($key, ':')] = $value;

                continue;
            }

            if (is_int($key)) {
                throw new DatabaseException('An array value can only be bound to a named parameter, e.g. "WHERE id IN (:ids)".');
            }

            $name = ltrim($key, ':');
            $placeholders = [];
            foreach (array_values($value) as $index => $item) {
                $placeholders[] = sprintf(':%s_%d', $name, $index);
                $expanded[sprintf('%s_%d', $name, $index)] = $item;
            }

            $sql = (string) preg_replace(
                sprintf('/:%s(?![A-Za-z0-9_])/', preg_quote($name, '/')),
                count($placeholders) === 0 ? 'NULL' : implode(', ', $placeholders),
                $sql,
            );
        }

        return [$sql, $expanded];
    }
}
