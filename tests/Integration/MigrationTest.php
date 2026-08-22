<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Tests\Integration;

use Rasuvaeff\Yii3UtmDb\Migration\M260801000000CreateUtmAttributionTable;
use Rasuvaeff\Yii3UtmDb\UtmAttributionTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Injector\Injector;

/**
 * Exercises the documented migration path. Namespace discovery is broken for
 * sibling core/backend namespaces, so consumers construct this migration
 * through Injector and invoke it directly.
 */
#[Test]
#[Covers(M260801000000CreateUtmAttributionTable::class)]
final class MigrationTest
{
    private ConnectionInterface $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new Connection(new Driver('sqlite::memory:'), new SchemaCache(new ArrayCache()));
        $this->migration()->up($this->builder());
    }

    public function createsEveryColumn(): void
    {
        $columns = \array_keys($this->db->getTableSchema('utm_attribution')?->getColumns() ?? []);

        Assert::same($columns, [
            'id', 'entity_id', 'event_id', 'interaction_type',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
            'click_ids', 'referrer', 'referrer_host', 'landing_page',
            'occurred_at', 'recorded_at', 'fingerprint', 'dedupe_key',
        ]);
    }

    public function createsEveryIndex(): void
    {
        $names = \array_map(
            static fn(array $row): string => (string) $row['name'],
            $this->db->createCommand("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'utm_attribution'")->queryAll(),
        );

        foreach (['uq_utm_attribution_dedupe', 'idx_utm_attribution_entity', 'idx_utm_attribution_entity_type', 'idx_utm_attribution_recorded'] as $expected) {
            Assert::true(\in_array($expected, $names, strict: true));
        }
    }

    public function createsIndexesOnTheDocumentedColumns(): void
    {
        $entityColumns = \array_map(
            static fn(array $row): string => (string) $row['name'],
            $this->db->createCommand('PRAGMA index_info(idx_utm_attribution_entity)')->queryAll(),
        );
        $entityTypeColumns = \array_map(
            static fn(array $row): string => (string) $row['name'],
            $this->db->createCommand('PRAGMA index_info(idx_utm_attribution_entity_type)')->queryAll(),
        );

        Assert::same($entityColumns, ['entity_id', 'recorded_at', 'id']);
        Assert::same($entityTypeColumns, ['entity_id', 'interaction_type', 'recorded_at', 'id']);
    }

    public function resolvesTheConfiguredTableNameThroughInjector(): void
    {
        $table = new UtmAttributionTableName('app_utm_attribution');
        $this->migration($table)->up($this->builder());

        Assert::instanceOf($this->db->getTableSchema('app_utm_attribution', true), \Yiisoft\Db\Schema\TableSchemaInterface::class);
    }

    public function isRevertible(): void
    {
        $this->migration()->down($this->builder());
        $this->db->getSchema()->refresh();

        Assert::null($this->db->getTableSchema('utm_attribution', true));
    }

    private function migration(?UtmAttributionTableName $table = null): M260801000000CreateUtmAttributionTable
    {
        $container = new Container(ContainerConfig::create()->withDefinitions([
            UtmAttributionTableName::class => $table ?? new UtmAttributionTableName(),
        ]));

        return (new Injector($container))->make(M260801000000CreateUtmAttributionTable::class);
    }

    private function builder(): MigrationBuilder
    {
        return new MigrationBuilder($this->db, new NullMigrationInformer());
    }
}
