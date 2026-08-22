<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Tests\Integration;

use Yiisoft\Db\Profiler\ContextInterface;
use Yiisoft\Db\Profiler\ProfilerInterface;

/**
 * Collects the raw SQL of every statement the connection executes.
 *
 * Batched deletion is invisible in the end state: seven rows are gone whether
 * one statement or four removed them. Counting the statements is the only way
 * a test can tell the two apart.
 */
final class RecordingProfiler implements ProfilerInterface
{
    /**
     * @var list<string>
     */
    private array $statements = [];

    #[\Override]
    public function begin(string $token, array|ContextInterface $context = []): void
    {
        $this->statements[] = $token;
    }

    #[\Override]
    public function end(string $token, array|ContextInterface $context = []): void {}

    /**
     * @return int<0, max>
     */
    public function countStartingWith(string $prefix): int
    {
        $count = 0;

        foreach ($this->statements as $statement) {
            if (\str_starts_with(\ltrim($statement), $prefix)) {
                ++$count;
            }
        }

        return $count;
    }
}
