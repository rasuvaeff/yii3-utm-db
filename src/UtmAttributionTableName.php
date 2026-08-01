<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb;

/**
 * The table's name, as a type.
 *
 * A plain `string $table` constructor argument cannot be configured:
 * `yiisoft/db-migration` builds migrations through `Injector::make()`, which
 * resolves arguments by name or by type from the container and never reads a
 * definition keyed by the migration's own class. A scalar has no type to
 * resolve, so the documented `M...::class => ['__construct()' => ...]` recipe
 * silently does nothing. A typed value object does resolve — and when the
 * container has no binding, `Injector` falls back to this parameter's default,
 * so the package still works unconfigured.
 *
 * @api
 */
final readonly class UtmAttributionTableName
{
    public const string DEFAULT = 'utm_attribution';

    private const string PATTERN = '/^[A-Za-z_]\w*(\.[A-Za-z_]\w*)?\z/';

    public function __construct(
        public string $value = self::DEFAULT,
    ) {
        if (\preg_match(self::PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid table name "%s"', $value));
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Base for index names: a schema-qualified table cannot appear verbatim in
     * an index name, so `schema.table` becomes `schema_table`. PostgreSQL
     * identifiers stop at 63 bytes, so the base is trimmed to leave room for
     * the `idx_`/`uq_` prefix and the suffix.
     */
    public function forIndexName(): string
    {
        return \substr(\str_replace('.', '_', $this->value), 0, 40);
    }
}
