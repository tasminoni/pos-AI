<?php
declare(strict_types=1);

final class QueryValidationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Generated SQL was rejected.');
    }
}
