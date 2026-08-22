<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\Yii3Utm\UtmAttributionRepository;
use Rasuvaeff\Yii3UtmDb\Command\PurgeUtmAttributionCommand;
use Rasuvaeff\Yii3UtmDb\DbUtmAttributionRepository;
use Rasuvaeff\Yii3UtmDb\UtmAttributionTableName;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * This package is the ONE source binding {@see UtmAttributionRepository}: the
 * core (rasuvaeff/yii3-utm) deliberately leaves it unbound, and a second
 * vendor package defining the same key makes `yiisoft/config` throw
 * `Duplicate key`.
 *
 * @var array $params
 */

$config = $params['rasuvaeff/yii3-utm-db'] ?? [];
$config = is_array($config) ? $config : [];

$table = $config['table'] ?? UtmAttributionTableName::DEFAULT;
$table = is_string($table) && $table !== '' ? $table : UtmAttributionTableName::DEFAULT;
$prefix = $config['table_prefix'] ?? '';
$table = (is_string($prefix) ? $prefix : '') . $table;

$retentionDays = $config['retentionDays'] ?? 365;
$retentionDays = is_int($retentionDays) && $retentionDays > 0 ? $retentionDays : 365;

$purgeBatchSize = $config['purgeBatchSize'] ?? DbUtmAttributionRepository::DEFAULT_PURGE_BATCH_SIZE;
$purgeBatchSize = is_int($purgeBatchSize) && $purgeBatchSize >= 1
    ? $purgeBatchSize
    : DbUtmAttributionRepository::DEFAULT_PURGE_BATCH_SIZE;

return [
    // the migration resolves this by type through Injector::make(), so the
    // repository and the migration can never disagree about the table
    UtmAttributionTableName::class => static fn (): UtmAttributionTableName
        => new UtmAttributionTableName($table),

    // The logger is optional on purpose: a malformed row is reported, never
    // thrown, so an application with a PSR-3 binding sees the skipped rows —
    // and one without a binding still resolves the repository instead of
    // failing at container build time.
    DbUtmAttributionRepository::class => static fn (
        ConnectionInterface $db,
        ClockInterface $clock,
        UtmAttributionTableName $tableName,
        ?LoggerInterface $logger = null,
    ): DbUtmAttributionRepository => new DbUtmAttributionRepository(
        db: $db,
        clock: $clock,
        table: $tableName,
        purgeBatchSize: $purgeBatchSize,
        logger: $logger ?? new NullLogger(),
    ),

    UtmAttributionRepository::class => DbUtmAttributionRepository::class,

    PurgeUtmAttributionCommand::class => static fn (
        UtmAttributionRepository $repository,
        ClockInterface $clock,
    ): PurgeUtmAttributionCommand => new PurgeUtmAttributionCommand(
        repository: $repository,
        clock: $clock,
        defaultDays: $retentionDays,
    ),
];
