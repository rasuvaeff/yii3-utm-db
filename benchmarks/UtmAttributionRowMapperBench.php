<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Benchmarks;

use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\Referrer;
use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Rasuvaeff\Yii3UtmDb\DbUtmAttributionRecord;
use Rasuvaeff\Yii3UtmDb\UtmAttributionRowMapper;
use Testo\Bench;

final class UtmAttributionRowMapperBench
{
    private static ?UtmAttributionRowMapper $mapper = null;
    private static ?UtmAttribution $attribution = null;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $row = null;

    #[Bench(
        callables: [
            'row-to-record' => [self::class, 'toRecord'],
        ],
        calls: 10_000,
        iterations: 10,
    )]
    public static function toRow(): array
    {
        return self::mapper()->toRow(
            self::attribution(),
            new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')),
        );
    }

    public static function toRecord(): DbUtmAttributionRecord
    {
        return self::mapper()->toRecord(self::$row ??= [...self::toRow(), 'id' => 42]);
    }

    private static function mapper(): UtmAttributionRowMapper
    {
        return self::$mapper ??= new UtmAttributionRowMapper();
    }

    private static function attribution(): UtmAttribution
    {
        return self::$attribution ??= new UtmAttribution(
            entityId: 'user-42',
            eventId: 'order-7',
            interactionType: InteractionType::purchase(),
            touchpoint: UtmTouchpoint::of(
                utm: new UtmParameters(source: 'google', medium: 'cpc', campaign: 'summer'),
                occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
                clickIds: ClickIds::fromArray(['gclid' => 'EAIaIQobChMI-example']),
                referrer: Referrer::of('https://ads.example.com/banner'),
                landingPage: 'https://shop.example.com/summer',
            ),
        );
    }
}
