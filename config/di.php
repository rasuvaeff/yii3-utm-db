<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
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

return [
    // the migration resolves this by type through Injector::make(), so the
    // repository and the migration can never disagree about the table
    UtmAttributionTableName::class => static fn (): UtmAttributionTableName
        => new UtmAttributionTableName($table),

    DbUtmAttributionRepository::class => static fn (
        ConnectionInterface $db,
        ClockInterface $clock,
        UtmAttributionTableName $tableName,
    ): DbUtmAttributionRepository => new DbUtmAttributionRepository(
        db: $db,
        clock: $clock,
        table: $tableName,
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
