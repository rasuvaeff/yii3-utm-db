<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb;

use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmAttributionRecord;

/**
 * A row of the attribution table.
 *
 * @api
 */
final readonly class DbUtmAttributionRecord implements UtmAttributionRecord
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        private string $id,
        private UtmAttribution $attribution,
        private \DateTimeImmutable $recordedAt,
    ) {}

    #[\Override]
    public function id(): string
    {
        return $this->id;
    }

    #[\Override]
    public function attribution(): UtmAttribution
    {
        return $this->attribution;
    }

    #[\Override]
    public function recordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
