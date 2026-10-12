<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager\Exceptions;

use Throwable;
use function sprintf;

/**
 * A statement failed. The SQL is kept for debugging; bound values are never included.
 */
class QueryException extends DatabaseException
{
    private readonly string $sql;

    public function __construct(string $sql, Throwable $previous)
    {
        parent::__construct(sprintf('%s (SQL: %s)', $previous->getMessage(), $sql), 0, $previous);

        $this->sql = $sql;
    }

    public function getSql(): string
    {
        return $this->sql;
    }
}
