<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Tests;

use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\Referrer;
use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Rasuvaeff\Yii3UtmDb\Exception\MalformedRow;
use Rasuvaeff\Yii3UtmDb\Exception\UtmDbException;
use Rasuvaeff\Yii3UtmDb\UtmAttributionRowMapper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(UtmAttributionRowMapper::class)]
final class UtmAttributionRowMapperTest
{
    private const string MISSING = '__missing_column__';

    private UtmAttributionRowMapper $mapper;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->mapper = new UtmAttributionRowMapper();
    }

    public function writesEveryColumn(): void
    {
        $row = $this->mapper->toRow($this->attribution(), $this->at('2026-08-01 12:00:00'));

        Assert::same(\array_keys($row), [
            'entity_id', 'event_id', 'interaction_type',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
            'click_ids', 'referrer', 'referrer_host', 'landing_page',
            'occurred_at', 'recorded_at', 'fingerprint', 'dedupe_key',
        ]);
        Assert::same($row['entity_id'], 'user-1');
        Assert::same($row['click_ids'], '{"gclid":"abc"}');
        Assert::same($row['referrer_host'], 'ads.example.com');
        Assert::same($row['occurred_at'], '2026-07-01 10:00:00.000000');
        Assert::same($row['recorded_at'], '2026-08-01 12:00:00.000000');
        Assert::same(\strlen((string) $row['dedupe_key']), 64);
    }

    public function writesNullReferrerColumnsWhenThereIsNoReferrer(): void
    {
        $withoutReferrer = new UtmAttribution(
            entityId: 'user-1',
            eventId: 'order-1',
            interactionType: InteractionType::purchase(),
            touchpoint: UtmTouchpoint::of(
                utm: new UtmParameters(source: 'google'),
                occurredAt: $this->at('2026-07-01 10:00:00'),
            ),
        );

        $row = $this->mapper->toRow($withoutReferrer, $this->at('2026-08-01 12:00:00'));

        Assert::null($row['referrer']);
        Assert::null($row['referrer_host']);
    }

    public function roundTripsThroughARow(): void
    {
        $attribution = $this->attribution();
        $row = $this->mapper->toRow($attribution, $this->at('2026-08-01 12:00:00'));
        $record = $this->mapper->toRecord([...$row, 'id' => 7]);

        Assert::same($record->id(), '7');
        Assert::same($record->attribution()->dedupeKey, $attribution->dedupeKey);
        Assert::same($record->attribution()->fingerprint, $attribution->fingerprint);
        Assert::same($record->attribution()->touchpoint->clickIds->get('gclid'), 'abc');
        Assert::same($record->attribution()->touchpoint->referrer?->host, 'ads.example.com');
        Assert::same($record->recordedAt()->format('Y-m-d H:i:s'), '2026-08-01 12:00:00');
    }

    public function acceptsTheCanonicalEmptyClickIdObject(): void
    {
        $withoutClickIds = new UtmAttribution(
            entityId: 'user-1',
            eventId: 'order-1',
            interactionType: InteractionType::purchase(),
            touchpoint: UtmTouchpoint::of(
                utm: new UtmParameters(source: 'google'),
                occurredAt: $this->at('2026-07-01 10:00:00'),
            ),
        );

        $row = [...$this->mapper->toRow($withoutClickIds, $this->at('2026-08-01 12:00:00')), 'id' => 1];

        $record = $this->mapper->toRecord([...$row, 'click_ids' => '{}']);

        Assert::true($record->attribution()->touchpoint->clickIds->isEmpty());
        Assert::same($record->attribution()->dedupeKey, $withoutClickIds->dedupeKey);
    }

    #[DataProvider('malformedRowsProvider')]
    public function rejectsMalformedRows(array $overrides, string $expectedFragment): void
    {
        $row = $this->validRow();

        foreach ($overrides as $column => $value) {
            if ($value === self::MISSING) {
                unset($row[$column]);
            } else {
                $row[$column] = $value;
            }
        }

        try {
            $this->mapper->toRecord($row);
        } catch (MalformedRow $e) {
            Assert::instanceOf($e, UtmDbException::class);
            Assert::string($e->getMessage())->contains($expectedFragment);

            return;
        }

        Assert::true(false);
    }

    public static function malformedRowsProvider(): iterable
    {
        yield 'missing id' => [['id' => null], 'id'];

        yield 'non-scalar id' => [['id' => ['x']], 'id'];

        yield 'missing entity' => [['entity_id' => null], 'entity_id'];

        yield 'empty entity' => [['entity_id' => ''], 'entity_id'];

        yield 'non-string column' => [['utm_source' => 42], 'is not a string'];

        yield 'unparsable time' => [['recorded_at' => 'yesterday'], 'cannot be parsed as a UTC timestamp'];

        yield 'invalid calendar date' => [['recorded_at' => '2026-02-31 12:00:00.000000'], 'contains an invalid calendar date'];

        yield 'timestamp without microseconds' => [['recorded_at' => '2026-08-01 12:00:00'], 'cannot be parsed as a UTC timestamp'];

        yield 'timestamp with a non-canonical month' => [['recorded_at' => '2026-8-01 12:00:00.000000'], 'is not a canonical UTC timestamp'];

        yield 'invalid interaction type' => [['interaction_type' => 'Purchase!'], 'interaction_type'];

        yield 'broken click ids' => [['click_ids' => '{not json'], 'is not valid JSON'];

        yield 'click ids not an object' => [['click_ids' => '"x"'], 'is not a JSON object'];

        yield 'click ids list' => [['click_ids' => '["x"]'], 'is not a JSON object'];

        yield 'non-canonical click ids' => [['click_ids' => '{"fbclid":"fb","gclid":"g"}'], 'is not canonical'];

        yield 'missing nullable column' => [['utm_term' => self::MISSING], 'utm_term'];

        yield 'non-canonical UTM value' => [['utm_source' => ' google '], 'utm_*'];

        yield 'referrer host without URL' => [['referrer' => null, 'referrer_host' => 'example.com'], 'referrer_host'];

        yield 'mismatched referrer host' => [['referrer_host' => 'other.example.com'], 'referrer_host'];
    }

    public function rejectsAnInvalidReferrerAtTheReferrerColumn(): void
    {
        try {
            $this->mapper->toRecord([...$this->validRow(), 'referrer' => 'not-a-url']);
        } catch (MalformedRow $e) {
            Assert::same(
                $e->getMessage(),
                'Malformed attribution row: column "referrer" is not a canonical absolute URL',
            );

            return;
        }

        Assert::true(false);
    }

    /**
     * The dedupe key is derived, so a stored one that disagrees means the row
     * was written by something other than this package — accepting it would
     * silently break the unique index the journal relies on.
     */
    public function rejectsAStoredDedupeKeyThatDoesNotMatch(): void
    {
        $row = [...$this->validRow(), 'dedupe_key' => \str_repeat('0', 64)];

        try {
            $this->mapper->toRecord($row);
        } catch (MalformedRow $e) {
            Assert::string($e->getMessage())->contains('dedupe_key');

            return;
        }

        Assert::true(false);
    }

    public function rejectsAStoredFingerprintThatDoesNotMatch(): void
    {
        $row = [...$this->validRow(), 'fingerprint' => \str_repeat('0', 64)];

        try {
            $this->mapper->toRecord($row);
        } catch (MalformedRow $e) {
            Assert::string($e->getMessage())->contains('fingerprint');

            return;
        }

        Assert::true(false);
    }

    public function formatsTimeInUtc(): void
    {
        $moscow = new \DateTimeImmutable('2026-08-01 15:00:00', new \DateTimeZone('Europe/Moscow'));

        Assert::same(UtmAttributionRowMapper::formatTime($moscow), '2026-08-01 12:00:00.000000');
    }

    /**
     * @return array<string, mixed>
     */
    private function validRow(): array
    {
        return [...$this->mapper->toRow($this->attribution(), $this->at('2026-08-01 12:00:00')), 'id' => 1];
    }

    private function attribution(): UtmAttribution
    {
        return new UtmAttribution(
            entityId: 'user-1',
            eventId: 'order-1',
            interactionType: InteractionType::purchase(),
            touchpoint: UtmTouchpoint::of(
                utm: new UtmParameters(source: 'google', medium: 'cpc', campaign: 'summer'),
                occurredAt: $this->at('2026-07-01 10:00:00'),
                clickIds: ClickIds::fromArray(['gclid' => 'abc']),
                referrer: Referrer::of('https://ads.example.com/banner'),
                landingPage: 'https://shop.example.com/summer',
            ),
        );
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }
}
