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
     * The id is read first and every later failure is re-thrown carrying it:
     * a single broken row aborts a whole `findByEntity()` batch, and a message
     * naming only the column leaves nothing to look the row up by.
     *
     * @param array<string, mixed> $row
     */
    public function toRecord(array $row): DbUtmAttributionRecord
    {
        $id = $this->requireScalar($row, 'id');

        try {
            return $this->record($row, $id);
        } catch (MalformedRow $e) {
            throw $e->forRow($id);
        }
    }

    public static function formatTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }

    /**
     * @param array<string, mixed> $row
     * @param non-empty-string $id
     */
    private function record(array $row, string $id): DbUtmAttributionRecord
    {
        $entityId = $this->requireString($row, 'entity_id');
        $eventId = $this->requireString($row, 'event_id');

        $attribution = new UtmAttribution(
            entityId: $entityId,
            eventId: $eventId,
            interactionType: $this->interactionType($row),
            touchpoint: $this->touchpoint($row),
        );

        $storedFingerprint = $this->requireString($row, 'fingerprint');

        if ($storedFingerprint !== $attribution->fingerprint) {
            throw MalformedRow::column('fingerprint', 'does not match the stored touchpoint');
        }

        $storedDedupeKey = $this->requireString($row, 'dedupe_key');

        if ($storedDedupeKey !== $attribution->dedupeKey) {
            throw MalformedRow::column('dedupe_key', 'does not match the stored touchpoint');
        }

        return new DbUtmAttributionRecord(
            id: $id,
            attribution: $attribution,
            recordedAt: $this->time($row, 'recorded_at'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function touchpoint(array $row): UtmTouchpoint
    {
        $landingPage = $this->optionalString($row, 'landing_page');

        $touchpoint = UtmTouchpoint::of(
            utm: $this->utm($row),
            occurredAt: $this->time($row, 'occurred_at'),
            clickIds: $this->clickIds($row),
            referrer: $this->referrer($row),
            landingPage: $landingPage,
        );

        if ($touchpoint->landingPage !== $landingPage) {
            throw MalformedRow::column('landing_page', 'is not canonical');
        }

        return $touchpoint;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function utm(array $row): UtmParameters
    {
        $values = [
            'utm_source' => $this->optionalString($row, 'utm_source'),
            'utm_medium' => $this->optionalString($row, 'utm_medium'),
            'utm_campaign' => $this->optionalString($row, 'utm_campaign'),
            'utm_term' => $this->optionalString($row, 'utm_term'),
            'utm_content' => $this->optionalString($row, 'utm_content'),
            'utm_id' => $this->optionalString($row, 'utm_id'),
        ];
        $utm = UtmParameters::fromArray($values);

        if ($utm->toArray() !== $values) {
            throw MalformedRow::column('utm_*', 'contains a non-canonical value');
        }

        return $utm;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function clickIds(array $row): ClickIds
    {
        $raw = $this->requireString($row, 'click_ids');

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw MalformedRow::column('click_ids', 'is not valid JSON');
        }

        if (!\is_array($decoded) || ($decoded !== [] && \array_is_list($decoded))) {
            throw MalformedRow::column('click_ids', 'is not a JSON object');
        }

        /** @var array<array-key, mixed> $decoded */
        $clickIds = ClickIds::fromArray($decoded);

        if ($clickIds->toJson() !== $raw) {
            throw MalformedRow::column('click_ids', 'is not canonical');
        }

        return $clickIds;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function referrer(array $row): ?Referrer
    {
        $url = $this->optionalString($row, 'referrer');
        $storedHost = $this->optionalString($row, 'referrer_host');

        if ($url === null) {
            if ($storedHost !== null) {
                throw MalformedRow::column('referrer_host', 'exists without a referrer');
            }

            return null;
        }

        $referrer = Referrer::of($url);

        if (!$referrer instanceof Referrer) {
            throw MalformedRow::column('referrer', 'is not a canonical absolute URL');
        }

        if ($storedHost !== $referrer->host) {
            throw MalformedRow::column('referrer_host', 'does not match the referrer URL');
        }

        return $referrer;
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
        $parsed = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();

        if ($parsed === false) {
            throw MalformedRow::column($column, 'cannot be parsed as a UTC timestamp');
        }

        if ($errors !== false && $errors['warning_count'] > 0) {
            throw MalformedRow::column($column, 'contains an invalid calendar date');
        }

        if ($parsed->format(self::TIME_FORMAT) !== $value) {
            throw MalformedRow::column($column, 'is not a canonical UTC timestamp');
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
        if (!\array_key_exists($column, $row)) {
            throw MalformedRow::column($column, 'is missing');
        }

        $value = $row[$column];

        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw MalformedRow::column($column, 'is not a string');
        }

        return $value;
    }
}
