<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmAttributionRecord;
use Rasuvaeff\Yii3Utm\UtmAttributionRepository;
use Rasuvaeff\Yii3UtmDb\Exception\MalformedRow;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Query\Query;

/**
 * Portable `yiisoft/db` implementation of the attribution journal.
 *
 * Every value is a bound parameter; the table and index names come only from
 * the validated {@see UtmAttributionTableName}.
 *
 * Ordering is `recorded_at, id` — both assigned by the server — so a late
 * delivery can never become the first touch of an entity, whatever timestamp
 * the client claims.
 *
 * @api
 */
final readonly class DbUtmAttributionRepository implements UtmAttributionRepository
{
    public const int DEFAULT_PURGE_BATCH_SIZE = 1000;

    private string $table;

    /**
     * @param int $purgeBatchSize rows deleted per statement by {@see self::purgeOlderThan()};
     *        a value below one would silently turn the retention sweep into a
     *        no-op and is rejected here
     * @param LoggerInterface $logger receives one detailed `error` per call plus one
     *        summary for any further rows skipped as malformed; the default
     *        silences the report, so bind the application logger
     */
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        UtmAttributionTableName $table = new UtmAttributionTableName(),
        private UtmAttributionRowMapper $mapper = new UtmAttributionRowMapper(),
        private int $purgeBatchSize = self::DEFAULT_PURGE_BATCH_SIZE,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        if ($purgeBatchSize < 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid purge batch size "%d"', $purgeBatchSize));
        }

        $this->table = $table->value;
    }

    /**
     * Race-safe by construction: a portable no-op upsert, with the
     * duplicate-key error handled for drivers that raise instead. A separate
     * "check, then insert" would leave a window in which two requests both see
     * nothing and both write.
     *
     * Only a duplicate-key conflict is swallowed as "already recorded"; any
     * other integrity failure — a foreign key, a CHECK or NOT NULL constraint
     * added to the table later — is re-thrown rather than silently counted
     * as a handled delivery.
     */
    #[\Override]
    public function append(UtmAttribution $attribution): bool
    {
        $row = $this->mapper->toRow($attribution, $this->clock->now());

        try {
            $affected = $this->db->createCommand()
                ->upsert($this->table, $row, false)
                ->execute();
        } catch (IntegrityException $e) {
            if (!IntegrityError::isDuplicateKey($e)) {
                throw $e;
            }

            return false;
        }

        return $affected > 0;
    }

    /**
     * A row the mapper rejects is skipped, not fatal.
     *
     * The mapper is strict on purpose — a row it does not recognise was written
     * by something else — but making that strictness fatal here means a single
     * foreign row hides every valid record of the entity, and the reader has no
     * way to see even the rows that are fine. The page therefore comes back
     * **shorter than `$limit`** when a row is skipped, and `$offset` counts
     * rows, not records; the skipped rows are reported to the logger with the
     * id of the first one.
     */
    #[\Override]
    public function findByEntity(
        string $entityId,
        ?InteractionType $interactionType = null,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $rows = $this->baseQuery($entityId, $interactionType)
            ->orderBy(['recorded_at' => SORT_ASC, 'id' => SORT_ASC])
            ->limit(\min($limit, self::MAX_LIMIT))
            ->offset($offset)
            ->all();

        $records = [];
        $skipped = [];

        /** @var mixed $row */
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $outcome = $this->map($row);

            if ($outcome instanceof UtmAttributionRecord) {
                $records[] = $outcome;
            } else {
                $skipped[] = $outcome;
            }
        }

        $this->reportSkipped($skipped);

        return $records;
    }

    #[\Override]
    public function findFirst(string $entityId, ?InteractionType $interactionType = null): ?UtmAttributionRecord
    {
        return $this->one($entityId, $interactionType, SORT_ASC);
    }

    #[\Override]
    public function findLast(string $entityId, ?InteractionType $interactionType = null): ?UtmAttributionRecord
    {
        return $this->one($entityId, $interactionType, SORT_DESC);
    }

    #[\Override]
    public function countByEntity(string $entityId, ?InteractionType $interactionType = null): int
    {
        $count = $this->baseQuery($entityId, $interactionType)->count();

        return \max(0, (int) $count);
    }

    #[\Override]
    public function deleteByEntity(string $entityId): int
    {
        $affected = $this->db->createCommand()
            ->delete($this->table, ['entity_id' => $entityId])
            ->execute();

        return \max(0, $affected);
    }

    /**
     * Deletes in batches of {@see $purgeBatchSize}, by primary key.
     *
     * `utm:purge` is the retention job for a table nothing else trims, so its
     * first production run removes months of rows. One unbounded `DELETE`
     * means one long transaction: undo/WAL growth, replication lag, and on
     * MySQL next-key locks across the whole `recorded_at` range. Selecting a
     * bounded set of ids and deleting those keeps each statement small, and it
     * is portable — `DELETE ... LIMIT` is not.
     */
    #[\Override]
    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        $cutoff = UtmAttributionRowMapper::formatTime($before);
        $deleted = 0;

        while (true) {
            /** @var list<mixed> $ids */
            $ids = (new Query($this->db))
                ->select('id')
                ->from($this->table)
                ->where(['<', 'recorded_at', $cutoff])
                ->limit($this->purgeBatchSize)
                ->column();

            // The loop ends on an empty selection rather than on a partial
            // batch: one extra bounded SELECT costs nothing next to a second
            // exit condition, and `['id' => []]` is an empty `IN`, which is a
            // trap worth not going anywhere near.
            if ($ids === []) {
                break;
            }

            $affected = $this->db->createCommand()->delete($this->table, ['id' => $ids])->execute();

            // Rows selected but none deleted: a concurrent purge got there
            // first, or the driver refuses to delete them (a BEFORE DELETE
            // trigger, a rule). The next pass would select the very same ids
            // and do nothing again — an unbounded loop holding a connection.
            // Retention is a repeating job; stopping loses nothing.
            if ($affected <= 0) {
                break;
            }

            $deleted += $affected;
        }

        return $deleted;
    }

    #[\Override]
    public function countOlderThan(\DateTimeImmutable $before): int
    {
        $count = (new Query($this->db))
            ->from($this->table)
            ->where(['<', 'recorded_at', UtmAttributionRowMapper::formatTime($before)])
            ->count();

        return \max(0, (int) $count);
    }

    /**
     * The first *readable* row in the requested direction.
     *
     * One row is fetched on the hot path. Only when that row turns out to be
     * malformed does the query widen: without it, a single foreign row at the
     * edge of an entity would make `findFirst()`/`findLast()` fail for that
     * entity forever, which is exactly the row an operator cannot delete
     * without first being able to read the rest. The widened scan is bounded by
     * {@see UtmAttributionRepository::MAX_LIMIT} — beyond that the table is not
     * "a foreign row", it is a foreign table, and null is the honest answer.
     */
    private function one(string $entityId, ?InteractionType $interactionType, int $direction): ?UtmAttributionRecord
    {
        $query = $this->baseQuery($entityId, $interactionType)
            ->orderBy(['recorded_at' => $direction, 'id' => $direction]);

        /** @var mixed $row */
        $row = $query->limit(1)->one();

        if (!\is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $outcome = $this->map($row);

        if ($outcome instanceof UtmAttributionRecord) {
            return $outcome;
        }

        // Offset 1: the row just rejected is already collected, and reporting
        // it twice for one call would double every malformed-row alert.
        $skipped = [$outcome];
        $rows = $query->limit(self::MAX_LIMIT)->offset(1)->all();

        /** @var mixed $next */
        foreach ($rows as $next) {
            if (!\is_array($next)) {
                continue;
            }

            /** @var array<string, mixed> $next */
            $outcome = $this->map($next);

            if ($outcome instanceof UtmAttributionRecord) {
                $this->reportSkipped($skipped);

                return $outcome;
            }

            $skipped[] = $outcome;
        }

        $this->reportSkipped($skipped);

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): UtmAttributionRecord|MalformedRow
    {
        try {
            return $this->mapper->toRecord($row);
        } catch (MalformedRow $e) {
            return $e;
        }
    }

    /**
     * One detailed report per call, one summary for the rest.
     *
     * Logging every skip of every read would let a single bulk import of
     * foreign rows fill the error channel with duplicates of one underlying
     * cause. The first skipped row keeps its full context — reason, table,
     * row id, exception; every further row of the same call is counted into a
     * single summary line.
     *
     * @param list<MalformedRow> $skipped
     */
    private function reportSkipped(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $first = $skipped[0];

        $this->logger->error(
            'Skipped a malformed UTM attribution row: {reason}',
            [
                'reason' => $first->getMessage(),
                'table' => $this->table,
                'rowId' => $first->rowId,
                'exception' => $first,
            ],
        );

        $rest = \count($skipped) - 1;

        if ($rest > 0) {
            $this->logger->error(
                'Skipped {count} more malformed UTM attribution rows in this call',
                ['count' => $rest, 'table' => $this->table],
            );
        }
    }

    private function baseQuery(string $entityId, ?InteractionType $interactionType): Query
    {
        $query = (new Query($this->db))
            ->from($this->table)
            ->where(['entity_id' => $entityId]);

        if ($interactionType instanceof InteractionType) {
            $query->andWhere(['interaction_type' => $interactionType->value]);
        }

        return $query;
    }
}
