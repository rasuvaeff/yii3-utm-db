<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Exception;

/**
 * A stored row does not match the schema this package writes.
 *
 * Raised instead of coercing: a row with a missing column or an unparsable
 * timestamp means the table was written by something else, and silently
 * turning it into a half-empty record would corrupt attribution reports.
 *
 * @api
 */
final class MalformedRow extends \RuntimeException implements UtmDbException
{
    /**
     * The inherited signature is kept intact and `$rowId` appended: this class
     * is `@api`, so narrowing `\RuntimeException::__construct()` would break
     * every caller that passes a code or a previous exception.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $rowId = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function column(string $column, string $reason): self
    {
        return new self(\sprintf('Malformed attribution row: column "%s" %s', $column, $reason));
    }

    /**
     * Names the offending row.
     *
     * The repository skips a malformed row instead of failing the query, so the
     * id is the only thing that leads back to it: without it the log line says
     * a row of some entity was dropped and nothing can be looked up.
     *
     * @param non-empty-string $rowId
     */
    public function forRow(string $rowId): self
    {
        return new self(
            \sprintf('%s (row id %s)', $this->getMessage(), $rowId),
            $this->getCode(),
            $this->getPrevious(),
            $rowId,
        );
    }
}
