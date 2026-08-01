<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Tests\Integration;

use Psr\Clock\ClockInterface;

/**
 * Clock a test can move, so that rows recorded at distinct server moments can
 * be ordered — `StaticClock` from yiisoft/test-support is final and stands
 * still.
 */
final class MutableClock implements ClockInterface
{
    public function __construct(
        private \DateTimeImmutable $now,
    ) {}

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(\sprintf('+%d seconds', $seconds));
    }
}
