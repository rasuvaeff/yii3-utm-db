<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Migration;

use Rasuvaeff\Yii3UtmDb\UtmAttributionTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Table behind rasuvaeff/yii3-utm-db.
 *
 * The unique index on `dedupe_key` is what turns idempotency into an invariant
 * rather than a check: two concurrent deliveries of one business event race to
 * insert the same key, and the database decides.
 *
 * Timestamps are fixed-width UTC strings (`Y-m-d H:i:s.u`) rather than native
 * datetimes: the format sorts lexicographically on every supported driver, so
 * the canonical order needs no driver-specific casting.
 *
 * @api
 */
final class M260801000000CreateUtmAttributionTable implements
    RevertibleMigrationInterface,
    TransactionalMigrationInterface
{
    public function __construct(
        private readonly UtmAttributionTableName $table = new UtmAttributionTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->createTable($this->table->value, [
            // ColumnBuilder, not the string 'bigprimarykey': that token does not
            // exist, and SQLite accepts an unknown type silently — the column ends
            // up without autoincrement and every id reads back as NULL.
            'id' => ColumnBuilder::bigPrimaryKey(),
            'entity_id' => 'string(191) NOT NULL',
            'event_id' => 'string(191) NOT NULL',
            'interaction_type' => 'string(32) NOT NULL',
            'utm_source' => 'string(255)',
            'utm_medium' => 'string(255)',
            'utm_campaign' => 'string(255)',
            'utm_term' => 'string(255)',
            'utm_content' => 'string(255)',
            'utm_id' => 'string(255)',
            // JSON object in whitelist order, '{}' when there is none
            'click_ids' => 'string(500)',
            'referrer' => 'string(500)',
            'referrer_host' => 'string(255)',
            'landing_page' => 'string(500)',
            // claimed by the source, analytics only
            'occurred_at' => 'string(30) NOT NULL',
            // server clock at insert time; with id it defines the canonical order
            'recorded_at' => 'string(30) NOT NULL',
            'fingerprint' => 'string(64) NOT NULL',
            'dedupe_key' => 'string(64) NOT NULL',
        ]);

        // index names follow the table name: in PostgreSQL they are unique per
        // schema, so a hard-coded name would collide between two installations
        // sharing one schema
        $index = $this->table->forIndexName();

        $b->createIndex($this->table->value, \sprintf('uq_%s_dedupe', $index), 'dedupe_key', 'UNIQUE');
        $b->createIndex($this->table->value, \sprintf('idx_%s_entity', $index), ['entity_id', 'recorded_at', 'id']);
        $b->createIndex(
            $this->table->value,
            \sprintf('idx_%s_entity_type', $index),
            ['entity_id', 'interaction_type', 'recorded_at', 'id'],
        );
        // retention sweeps scan by recorded time alone
        $b->createIndex($this->table->value, \sprintf('idx_%s_recorded', $index), 'recorded_at');
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->table->value);
    }
}
