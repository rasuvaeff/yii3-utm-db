<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Tests\Integration;

use Psr\Log\LogLevel;
use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\Referrer;
use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmAttributionEvent;
use Rasuvaeff\Yii3Utm\UtmAttributionRecord;
use Rasuvaeff\Yii3Utm\UtmAttributionService;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Rasuvaeff\Yii3UtmDb\DbUtmAttributionRepository;
use Rasuvaeff\Yii3UtmDb\Exception\MalformedRow;
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
use Yiisoft\Test\Support\Log\SimpleLogger;

/**
 * Exercises the real repository on SQLite: the unique index behind
 * deduplication, canonical ordering and retention/erasure operations.
 */
#[Test]
#[Covers(DbUtmAttributionRepository::class)]
final class SqliteIntegrationTest
{
    // The concrete driver type, not ConnectionInterface: the veto-trigger
    // fixture below needs the raw PDO handle, because PDO::prepare() cannot
    // carry a trigger body — it stops at the first `;` inside BEGIN … END.
    private Connection $db;
    private MutableClock $clock;
    private DbUtmAttributionRepository $repository;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new Connection(new Driver('sqlite::memory:'), new SchemaCache(new ArrayCache()));
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')));

        $table = new UtmAttributionTableName();
        $builder = new MigrationBuilder($this->db, new NullMigrationInformer());

        $container = new Container(ContainerConfig::create()->withDefinitions([
            UtmAttributionTableName::class => $table,
        ]));
        $migration = (new Injector($container))->make(M260801000000CreateUtmAttributionTable::class);
        $migration->up($builder);

        $this->repository = new DbUtmAttributionRepository(
            db: $this->db,
            clock: $this->clock,
            table: $table,
        );
    }

    public function storesAndReadsBackAFullRow(): void
    {
        $this->repository->append($this->attribution());

        $record = $this->repository->findFirst('user-1');

        Assert::instanceOf($record, UtmAttributionRecord::class);
        Assert::same($record?->attribution()->entityId, 'user-1');
        Assert::same($record?->attribution()->touchpoint->utm->campaign, 'summer');
        Assert::same($record?->attribution()->touchpoint->clickIds->get('gclid'), 'abc');
        Assert::same($record?->attribution()->touchpoint->referrer?->host, 'ads.example.com');
        Assert::same($record?->recordedAt()->format('Y-m-d H:i:s'), '2026-08-01 12:00:00');
    }

    public function theSameEventTwiceLeavesOneRow(): void
    {
        Assert::true($this->repository->append($this->attribution()));
        Assert::false($this->repository->append($this->attribution()));
        Assert::same($this->repository->countByEntity('user-1'), 1);
    }

    public function oneEventWithTwoTouchpointsWritesTwoRows(): void
    {
        $service = new UtmAttributionService($this->repository);

        $created = $service->record(new UtmAttributionEvent(
            entityId: 'user-1',
            eventId: 'order-1',
            interactionType: InteractionType::purchase(),
            history: UtmHistory::of(
                $this->touchpoint('google', '2026-07-01 10:00:00'),
                $this->touchpoint('newsletter', '2026-07-20 10:00:00'),
            ),
        ));

        Assert::same($created, 2);
        Assert::same($this->repository->countByEntity('user-1'), 2);

        // Redelivery of the same event heals nothing and duplicates nothing.
        Assert::same($service->record(new UtmAttributionEvent(
            entityId: 'user-1',
            eventId: 'order-1',
            interactionType: InteractionType::purchase(),
            history: UtmHistory::of(
                $this->touchpoint('google', '2026-07-01 10:00:00'),
                $this->touchpoint('newsletter', '2026-07-20 10:00:00'),
            ),
        )), 0);
        Assert::same($this->repository->countByEntity('user-1'), 2);
    }

    public function firstTouchFollowsServerOrderNotTheClaimedTime(): void
    {
        $this->repository->append($this->attribution(eventId: 'order-1', source: 'google', occurredAt: '2026-07-01 10:00:00'));

        $this->clock->advance(3600);
        // A later delivery claims to have happened years earlier.
        $this->repository->append($this->attribution(eventId: 'order-2', source: 'spoofed', occurredAt: '2000-01-01 00:00:00'));

        Assert::same($this->repository->findFirst('user-1')?->attribution()->touchpoint->utm->source, 'google');
        Assert::same($this->repository->findLast('user-1')?->attribution()->touchpoint->utm->source, 'spoofed');
    }

    public function queriesAreScopedByEntityAndType(): void
    {
        $this->repository->append($this->attribution(eventId: 'order-1'));
        $this->clock->advance(1);
        $this->repository->append($this->attribution(eventId: 'reg-1', type: InteractionType::registration()));
        $this->clock->advance(1);
        $this->repository->append($this->attribution(entityId: 'user-2', eventId: 'order-9'));

        Assert::same($this->repository->countByEntity('user-1'), 2);
        Assert::same($this->repository->countByEntity('user-1', InteractionType::purchase()), 1);
        Assert::same($this->repository->countByEntity('user-2'), 1);
        Assert::same(
            $this->repository->findFirst('user-1', InteractionType::registration())?->attribution()->eventId,
            'reg-1',
        );
        Assert::null($this->repository->findFirst('user-3'));
    }

    public function findByEntityPaginatesInCanonicalOrder(): void
    {
        foreach (['a', 'b', 'c'] as $index => $source) {
            $this->clock->advance($index);
            $this->repository->append($this->attribution(eventId: 'order-' . $source, source: $source));
        }

        $sources = \array_map(
            static fn(UtmAttributionRecord $r): ?string => $r->attribution()->touchpoint->utm->source,
            $this->repository->findByEntity('user-1', limit: 2, offset: 1),
        );

        Assert::same($sources, ['b', 'c']);
    }

    public function orderingFollowsRecordedTimeNotInsertionId(): void
    {
        $this->repository->append($this->attribution(eventId: 'order-first', source: 'first'));
        $this->clock->advance(10);
        $this->repository->append($this->attribution(eventId: 'order-second', source: 'second'));

        // Simulate replica lag / clock skew between two writers: the row
        // that got the higher id ends up with the earlier recorded_at.
        // Ordering is documented as `recorded_at, id` (AGENTS.md) precisely
        // so this can't happen — id alone would put 'first' first here.
        $this->db->createCommand()
            ->update('utm_attribution', ['recorded_at' => '2000-01-01 00:00:00.000000'], ['utm_source' => 'second'])
            ->execute();

        Assert::same($this->repository->findFirst('user-1')?->attribution()->touchpoint->utm->source, 'second');
        Assert::same($this->repository->findLast('user-1')?->attribution()->touchpoint->utm->source, 'first');

        $sources = \array_map(
            static fn(UtmAttributionRecord $r): ?string => $r->attribution()->touchpoint->utm->source,
            $this->repository->findByEntity('user-1'),
        );
        Assert::same($sources, ['second', 'first']);
    }

    public function retentionAndErasure(): void
    {
        $this->repository->append($this->attribution(eventId: 'old'));
        $this->clock->advance(7200);
        $this->repository->append($this->attribution(eventId: 'new'));
        $this->repository->append($this->attribution(entityId: 'user-2', eventId: 'other'));

        // countOlderThan reports the same figure purgeOlderThan is about to
        // remove, and does not itself remove anything
        Assert::same($this->repository->countOlderThan(new \DateTimeImmutable('2026-08-01 13:00:00', new \DateTimeZone('UTC'))), 1);
        Assert::same($this->repository->countByEntity('user-1'), 2);

        $purged = $this->repository->purgeOlderThan(new \DateTimeImmutable('2026-08-01 13:00:00', new \DateTimeZone('UTC')));

        Assert::same($purged, 1);
        Assert::same($this->repository->countByEntity('user-1'), 1);

        // nothing left before that cut-off: the count is exactly 0, not
        // clamped up to 1
        Assert::same($this->repository->countOlderThan(new \DateTimeImmutable('2026-08-01 13:00:00', new \DateTimeZone('UTC'))), 0);
        Assert::same($this->repository->purgeOlderThan(new \DateTimeImmutable('2026-08-01 13:00:00', new \DateTimeZone('UTC'))), 0);

        Assert::same($this->repository->deleteByEntity('user-1'), 1);
        Assert::same($this->repository->countByEntity('user-1'), 0);
        Assert::same($this->repository->countByEntity('user-2'), 1);

        // an entity with no rows deletes exactly 0, not clamped up to 1
        Assert::same($this->repository->deleteByEntity('user-3'), 0);
    }

    /**
     * The first production run of `utm:purge` removes months of rows, so it
     * must not be a single statement. The end state cannot tell the two apart
     * — the rows are gone either way — so the profiler counts the statements:
     * seven rows at a batch size of two is four `DELETE`s, and an unbounded
     * delete would be one.
     */
    public function purgesInBatches(): void
    {
        $profiler = new RecordingProfiler();
        $repository = new DbUtmAttributionRepository(
            db: $this->db,
            clock: $this->clock,
            table: new UtmAttributionTableName(),
            purgeBatchSize: 2,
        );

        for ($i = 0; $i < 7; ++$i) {
            $repository->append($this->attribution(eventId: 'order-' . $i));
        }

        $this->clock->advance(7200);
        $repository->append($this->attribution(eventId: 'kept'));

        $cutoff = new \DateTimeImmutable('2026-08-01 13:00:00', new \DateTimeZone('UTC'));

        Assert::same($repository->countOlderThan($cutoff), 7);

        $this->db->setProfiler($profiler);

        Assert::same($repository->purgeOlderThan($cutoff), 7);
        Assert::same($profiler->countStartingWith('DELETE'), 4);
        Assert::same($repository->countOlderThan($cutoff), 0);
        Assert::same($repository->countByEntity('user-1'), 1);
        Assert::same($repository->purgeOlderThan($cutoff), 0);
    }

    /**
     * A batch size that divides the row count exactly must still terminate:
     * the final round finds nothing and stops, rather than looping forever on
     * a full-looking batch.
     */
    public function purgesAnExactMultipleOfTheBatchSize(): void
    {
        $repository = new DbUtmAttributionRepository(
            db: $this->db,
            clock: $this->clock,
            table: new UtmAttributionTableName(),
            purgeBatchSize: 2,
        );

        for ($i = 0; $i < 4; ++$i) {
            $repository->append($this->attribution(eventId: 'order-' . $i));
        }

        $cutoff = new \DateTimeImmutable('2026-08-01 13:00:00', new \DateTimeZone('UTC'));

        Assert::same($repository->purgeOlderThan($cutoff), 4);
        Assert::same($repository->countByEntity('user-1'), 0);
    }

    /**
     * A batch whose `DELETE` removes nothing must end the loop.
     *
     * The trigger below is the portable way to produce that state on demand —
     * `RAISE(IGNORE)` in a `BEFORE DELETE` vetoes the row while the statement
     * succeeds with zero affected rows — but the real one is a concurrent purge
     * that got to the same ids first. Without the guard, the next pass selects
     * the very same ids and this test never returns.
     */
    public function purgeStopsWhenABatchDeletesNothing(): void
    {
        $repository = new DbUtmAttributionRepository(
            db: $this->db,
            clock: $this->clock,
            table: new UtmAttributionTableName(),
            purgeBatchSize: 2,
        );

        for ($i = 0; $i < 3; ++$i) {
            $repository->append($this->attribution(eventId: 'order-' . $i));
        }

        $pdo = $this->db->getPdo();

        Assert::instanceOf($pdo, \PDO::class);

        $pdo?->exec(
            'CREATE TRIGGER veto_delete BEFORE DELETE ON ' . (new UtmAttributionTableName())->value
            . ' BEGIN SELECT RAISE(IGNORE); END',
        );

        $cutoff = new \DateTimeImmutable('2026-08-01 13:00:00', new \DateTimeZone('UTC'));

        Assert::same($repository->purgeOlderThan($cutoff), 0);
        Assert::same($repository->countOlderThan($cutoff), 3);
    }

    /**
     * A row this package did not write must not hide the ones it did.
     *
     * The mapper is strict by design, but making that strictness fatal here
     * means one foreign row blanks the whole attribution history of an entity.
     * The row is skipped and reported instead — with its id, which is the only
     * thing that leads an operator back to it.
     */
    public function aMalformedRowIsSkippedAndReportedInsteadOfHidingTheEntity(): void
    {
        $logger = new SimpleLogger();
        $repository = $this->repositoryWith($logger);

        $this->appendSources($repository, 'a', 'b', 'c');
        $brokenId = $this->breakRow('order-b');

        Assert::same($this->sourcesOf($repository->findByEntity('user-1')), ['a', 'c']);

        // The count is rows, not readable records: a page can come back
        // shorter than its limit, and this is the number that says why.
        Assert::same($repository->countByEntity('user-1'), 3);

        $messages = $logger->getMessages();

        Assert::same(\count($messages), 1);
        Assert::same($messages[0]['level'], LogLevel::ERROR);
        Assert::string((string) $messages[0]['message'])->contains('fingerprint');
        Assert::string((string) $messages[0]['message'])->contains('(row id ' . $brokenId . ')');
        Assert::same($messages[0]['context']['rowId'], $brokenId);
        Assert::same($messages[0]['context']['table'], 'utm_attribution');
        Assert::instanceOf($messages[0]['context']['exception'], MalformedRow::class);
    }

    /**
     * `findFirst()`/`findLast()` read a single row, so a malformed row at the
     * edge of an entity is exactly the one they hit — and the one an operator
     * cannot remove until the rest of the history is readable again.
     */
    public function findFirstAndFindLastStepOverAMalformedEdgeRow(): void
    {
        $logger = new SimpleLogger();
        $repository = $this->repositoryWith($logger);

        $this->appendSources($repository, 'a', 'b', 'c');
        $this->breakRow('order-a');
        $this->breakRow('order-c');

        Assert::same($repository->findFirst('user-1')?->attribution()->touchpoint->utm->source, 'b');
        Assert::same($repository->findLast('user-1')?->attribution()->touchpoint->utm->source, 'b');

        // One report per call, not per row scanned: the hot-path row is
        // reported once and the widened scan starts after it.
        Assert::same(\count($logger->getMessages()), 2);
    }

    /**
     * When nothing in the table is readable, the answer is "nothing", not an
     * exception — and every skipped row is reported, so the silence is loud in
     * the log.
     */
    public function anEntityOfNothingButMalformedRowsReadsAsEmpty(): void
    {
        $logger = new SimpleLogger();
        $repository = $this->repositoryWith($logger);

        $this->appendSources($repository, 'a', 'b');
        $this->breakRow('order-a');
        $this->breakRow('order-b');

        Assert::same($repository->findByEntity('user-1'), []);
        Assert::null($repository->findFirst('user-1'));
        Assert::null($repository->findLast('user-1'));

        // 2 from findByEntity, then 2 per single-row read: the row it fetched
        // plus the one row the widened scan reaches.
        Assert::same(\count($logger->getMessages()), 6);
    }

    private function repositoryWith(SimpleLogger $logger): DbUtmAttributionRepository
    {
        return new DbUtmAttributionRepository(
            db: $this->db,
            clock: $this->clock,
            table: new UtmAttributionTableName(),
            logger: $logger,
        );
    }

    private function appendSources(DbUtmAttributionRepository $repository, string ...$sources): void
    {
        foreach ($sources as $source) {
            $repository->append($this->attribution(eventId: 'order-' . $source, source: $source));
            $this->clock->advance(1);
        }
    }

    /**
     * Corrupts one row the way a foreign writer would: the stored fingerprint
     * no longer matches the touchpoint in the same row. Breaking it on the
     * fingerprint rather than on, say, a rejected referrer scheme keeps the
     * fixture independent of core behaviour that is not released yet.
     *
     * @return string the id of the row it broke
     */
    private function breakRow(string $eventId): string
    {
        $table = (new UtmAttributionTableName())->value;

        $this->db->createCommand()
            ->update($table, ['fingerprint' => \str_repeat('0', 64)], ['event_id' => $eventId])
            ->execute();

        return (string) $this->db
            ->createCommand('SELECT id FROM ' . $table . ' WHERE event_id = :event_id', [':event_id' => $eventId])
            ->queryScalar();
    }

    /**
     * @param list<UtmAttributionRecord> $records
     *
     * @return list<string|null>
     */
    private function sourcesOf(array $records): array
    {
        return \array_map(
            static fn(UtmAttributionRecord $r): ?string => $r->attribution()->touchpoint->utm->source,
            $records,
        );
    }

    private function attribution(
        string $entityId = 'user-1',
        string $eventId = 'order-1',
        string $source = 'google',
        string $occurredAt = '2026-07-01 10:00:00',
        ?InteractionType $type = null,
    ): UtmAttribution {
        return new UtmAttribution(
            entityId: $entityId,
            eventId: $eventId,
            interactionType: $type ?? InteractionType::purchase(),
            touchpoint: UtmTouchpoint::of(
                utm: new UtmParameters(source: $source, medium: 'cpc', campaign: 'summer'),
                occurredAt: new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')),
                clickIds: ClickIds::fromArray(['gclid' => 'abc']),
                referrer: Referrer::of('https://ads.example.com/banner'),
                landingPage: 'https://shop.example.com/summer',
            ),
        );
    }

    private function touchpoint(string $source, string $time): UtmTouchpoint
    {
        return UtmTouchpoint::of(
            utm: new UtmParameters(source: $source),
            occurredAt: new \DateTimeImmutable($time, new \DateTimeZone('UTC')),
        );
    }
}
