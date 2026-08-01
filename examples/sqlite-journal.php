<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmAttributionEvent;
use Rasuvaeff\Yii3Utm\UtmAttributionRecord;
use Rasuvaeff\Yii3Utm\UtmAttributionService;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Rasuvaeff\Yii3UtmDb\DbUtmAttributionRepository;
use Rasuvaeff\Yii3UtmDb\Migration\M260801000000CreateUtmAttributionTable;
use Rasuvaeff\Yii3UtmDb\UtmAttributionTableName;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;

require_once __DIR__ . '/../vendor/autoload.php';

$utc = new DateTimeZone('UTC');

$clock = new class ($utc) implements ClockInterface {
    private DateTimeImmutable $now;

    public function __construct(DateTimeZone $utc)
    {
        $this->now = new DateTimeImmutable('2026-08-01 12:00:00', $utc);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceDays(int $days): void
    {
        $this->now = $this->now->modify(sprintf('+%d days', $days));
    }
};

$db = new Connection(new Driver('sqlite::memory:'), new SchemaCache(new ArrayCache()));
$table = new UtmAttributionTableName();

// The documented migration path: construct and call up(). Namespace discovery
// does not work for *-db packages of this monorepo.
(new M260801000000CreateUtmAttributionTable($table))->up(new MigrationBuilder($db, new NullMigrationInformer()));

$repository = new DbUtmAttributionRepository(db: $db, clock: $clock, table: $table);
$service = new UtmAttributionService($repository);

$touchpoint = static fn(string $source, string $medium, string $day, array $clickIds = []): UtmTouchpoint
    => UtmTouchpoint::of(
        utm: new UtmParameters(source: $source, medium: $medium),
        occurredAt: new DateTimeImmutable("2026-07-{$day} 10:00:00", new DateTimeZone('UTC')),
        clickIds: ClickIds::fromArray($clickIds),
    );

$history = UtmHistory::of(
    $touchpoint('google', 'cpc', '01', ['gclid' => 'EAIaIQobChMI-example']),
    $touchpoint('newsletter', 'email', '20'),
);

$purchase = new UtmAttributionEvent(
    entityId: 'user-42',
    eventId: 'order-7',
    interactionType: InteractionType::purchase(),
    history: $history,
);

printf("first delivery ..... %d rows\n", $service->record($purchase));

$clock->advanceDays(1);
printf("retry .............. %d rows\n", $service->record($purchase));

// A later delivery claiming an ancient touch time cannot become the first row.
$clock->advanceDays(1);
$service->record(new UtmAttributionEvent(
    entityId: 'user-42',
    eventId: 'order-8',
    interactionType: InteractionType::purchase(),
    history: UtmHistory::of(
        UtmTouchpoint::of(
            utm: new UtmParameters(source: 'spoofed', medium: 'cpc'),
            occurredAt: new DateTimeImmutable('2000-01-01 00:00:00', $utc),
        ),
    ),
));

printf("\njournal (canonical order):\n");

foreach ($repository->findByEntity('user-42') as $record) {
    printf(
        "  #%-2s recorded %s  claimed %s  %-10s %s\n",
        $record->id(),
        $record->recordedAt()->format('m-d H:i'),
        $record->attribution()->touchpoint->occurredAt->format('Y-m-d'),
        (string) $record->attribution()->touchpoint->utm->source,
        $record->attribution()->touchpoint->clickIds->toJson(),
    );
}

$first = $repository->findFirst('user-42');
$last = $repository->findLast('user-42');

printf("\nfirst touch ........ %s (a backdated delivery did not take this place)\n", (string) $first?->attribution()->touchpoint->utm->source);
printf("last touch ......... %s\n", (string) $last?->attribution()->touchpoint->utm->source);
printf("purchases .......... %d\n", $repository->countByEntity('user-42', InteractionType::purchase()));

$purged = $repository->purgeOlderThan(new DateTimeImmutable('2026-08-02 00:00:00', $utc));
printf("\npurged by retention  %d\n", $purged);
printf("erased on request .. %d\n", $repository->deleteByEntity('user-42'));

assert($first instanceof UtmAttributionRecord);
