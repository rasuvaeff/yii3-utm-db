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
    public static function column(string $column, string $reason): self
    {
        return new self(\sprintf('Malformed attribution row: column "%s" %s', $column, $reason));
    }
}
