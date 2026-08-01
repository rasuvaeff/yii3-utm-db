<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmAttributionRecord;
use Rasuvaeff\Yii3Utm\UtmAttributionRepository;
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
    private string $table;

    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        UtmAttributionTableName $table = new UtmAttributionTableName(),
        private UtmAttributionRowMapper $mapper = new UtmAttributionRowMapper(),
    ) {
        $this->table = $table->value;
    }

    /**
     * Race-safe by construction: a portable no-op upsert, with the
     * duplicate-key error handled for drivers that raise instead. A separate
     * "check, then insert" would leave a window in which two requests both see
     * nothing and both write.
     */
    #[\Override]
    public function append(UtmAttribution $attribution): bool
    {
        $row = $this->mapper->toRow($attribution, $this->clock->now());

        try {
            $affected = $this->db->createCommand()
                ->upsert($this->table, $row, false)
                ->execute();
        } catch (IntegrityException) {
            return false;
        }

        return $affected > 0;
    }

    #[\Override]
    public function findByEntity(
        string $entityId,
        ?InteractionType $interactionType = null,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $rows = $this->baseQuery($entityId, $interactionType)
            ->orderBy(['recorded_at' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        $records = [];

        /** @var mixed $row */
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $records[] = $this->mapper->toRecord($row);
        }

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

    #[\Override]
    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        $affected = $this->db->createCommand()
            ->delete($this->table, ['<', 'recorded_at', UtmAttributionRowMapper::formatTime($before)])
            ->execute();

        return \max(0, $affected);
    }

    private function one(string $entityId, ?InteractionType $interactionType, int $direction): ?UtmAttributionRecord
    {
        /** @var mixed $row */
        $row = $this->baseQuery($entityId, $interactionType)
            ->orderBy(['recorded_at' => $direction, 'id' => $direction])
            ->limit(1)
            ->one();

        if (!\is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $this->mapper->toRecord($row);
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
