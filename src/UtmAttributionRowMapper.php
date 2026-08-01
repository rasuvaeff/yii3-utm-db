<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb;

use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\Referrer;
use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Rasuvaeff\Yii3UtmDb\Exception\MalformedRow;

/**
 * Row ⇄ record, strictly.
 *
 * Timestamps are stored as fixed-width UTC strings so that ordering is
 * lexicographic on every driver; a value that does not parse means the table
 * was written by something else, and the mapper says so instead of guessing.
 *
 * @psalm-type UtmAttributionRow = array{
 *     id: int|string,
 *     entity_id: string,
 *     event_id: string,
 *     interaction_type: string,
 *     utm_source: string|null,
 *     utm_medium: string|null,
 *     utm_campaign: string|null,
 *     utm_term: string|null,
 *     utm_content: string|null,
 *     utm_id: string|null,
 *     click_ids: string|null,
 *     referrer: string|null,
 *     referrer_host: string|null,
 *     landing_page: string|null,
 *     occurred_at: string,
 *     recorded_at: string,
 *     fingerprint: string,
 *     dedupe_key: string,
 * }
 *
 * @api
 */
final readonly class UtmAttributionRowMapper
{
    public const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string|null>
     */
    public function toRow(UtmAttribution $attribution, \DateTimeImmutable $recordedAt): array
    {
        $touchpoint = $attribution->touchpoint;

        return [
            'entity_id' => $attribution->entityId,
            'event_id' => $attribution->eventId,
            'interaction_type' => $attribution->interactionType->value,
            'utm_source' => $touchpoint->utm->source,
            'utm_medium' => $touchpoint->utm->medium,
            'utm_campaign' => $touchpoint->utm->campaign,
            'utm_term' => $touchpoint->utm->term,
            'utm_content' => $touchpoint->utm->content,
            'utm_id' => $touchpoint->utm->id,
            'click_ids' => $touchpoint->clickIds->toJson(),
            'referrer' => $touchpoint->referrer?->url,
            'referrer_host' => $touchpoint->referrer?->host,
            'landing_page' => $touchpoint->landingPage,
            'occurred_at' => self::formatTime($touchpoint->occurredAt),
            'recorded_at' => self::formatTime($recordedAt),
            'fingerprint' => $attribution->fingerprint,
            'dedupe_key' => $attribution->dedupeKey,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function toRecord(array $row): DbUtmAttributionRecord
    {
        $id = $this->requireScalar($row, 'id');
        $entityId = $this->requireString($row, 'entity_id');
        $eventId = $this->requireString($row, 'event_id');

        $attribution = new UtmAttribution(
            entityId: $entityId,
            eventId: $eventId,
            interactionType: $this->interactionType($row),
            touchpoint: $this->touchpoint($row),
        );

        $storedDedupeKey = $this->optionalString($row, 'dedupe_key');

        if ($storedDedupeKey !== null && $storedDedupeKey !== $attribution->dedupeKey) {
            throw MalformedRow::column('dedupe_key', 'does not match the stored touchpoint');
        }

        return new DbUtmAttributionRecord(
            id: $id,
            attribution: $attribution,
            recordedAt: $this->time($row, 'recorded_at'),
        );
    }

    public static function formatTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function touchpoint(array $row): UtmTouchpoint
    {
        $referrer = $this->optionalString($row, 'referrer');

        return UtmTouchpoint::of(
            utm: new UtmParameters(
                source: $this->optionalString($row, 'utm_source'),
                medium: $this->optionalString($row, 'utm_medium'),
                campaign: $this->optionalString($row, 'utm_campaign'),
                term: $this->optionalString($row, 'utm_term'),
                content: $this->optionalString($row, 'utm_content'),
                id: $this->optionalString($row, 'utm_id'),
            ),
            occurredAt: $this->time($row, 'occurred_at'),
            clickIds: $this->clickIds($row),
            referrer: $referrer === null ? null : Referrer::of($referrer),
            landingPage: $this->optionalString($row, 'landing_page'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function clickIds(array $row): ClickIds
    {
        $raw = $this->optionalString($row, 'click_ids');

        if ($raw === null || $raw === '' || $raw === '{}') {
            return ClickIds::empty();
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw MalformedRow::column('click_ids', 'is not valid JSON');
        }

        if (!\is_array($decoded)) {
            throw MalformedRow::column('click_ids', 'is not a JSON object');
        }

        return ClickIds::fromArray($decoded);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function interactionType(array $row): InteractionType
    {
        try {
            return InteractionType::of($this->requireString($row, 'interaction_type'));
        } catch (\InvalidArgumentException) {
            throw MalformedRow::column('interaction_type', 'is not a valid interaction type');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function time(array $row, string $column): \DateTimeImmutable
    {
        $value = $this->requireString($row, $column);
        $parsed = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $value, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        if ($parsed === false) {
            throw MalformedRow::column($column, 'is not a UTC timestamp');
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return non-empty-string
     */
    private function requireScalar(array $row, string $column): string
    {
        /** @var mixed $value */
        $value = $row[$column] ?? null;

        if (!\is_string($value) && !\is_int($value)) {
            throw MalformedRow::column($column, 'is missing');
        }

        $string = (string) $value;

        if ($string === '') {
            throw MalformedRow::column($column, 'is empty');
        }

        return $string;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return non-empty-string
     */
    private function requireString(array $row, string $column): string
    {
        $value = $this->optionalString($row, $column);

        if ($value === null || $value === '') {
            throw MalformedRow::column($column, 'is missing or empty');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function optionalString(array $row, string $column): ?string
    {
        /** @var mixed $value */
        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw MalformedRow::column($column, 'is not a string');
        }

        return $value;
    }
}
