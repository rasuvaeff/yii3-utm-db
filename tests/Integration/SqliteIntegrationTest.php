<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Tests\Integration;

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
 * Exercises the real repository on SQLite: the unique index behind
 * deduplication, canonical ordering and retention/erasure operations.
 */
#[Test]
#[Covers(DbUtmAttributionRepository::class)]
final class SqliteIntegrationTest
{
    private ConnectionInterface $db;
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
