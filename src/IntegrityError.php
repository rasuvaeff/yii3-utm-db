<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb;

/**
 * Tells a duplicate-key integrity error from every other kind.
 *
 * `DbUtmAttributionRepository::append()` may swallow an `IntegrityException`
 * as "the row was already there" only when the error provably names a
 * duplicate key — or when no driver signature matches at all, which keeps
 * exotic drivers on the legacy tolerant path. A recognised foreign-key,
 * CHECK or NOT NULL violation returns `false` here and is re-thrown by the
 * caller instead of being counted as a handled delivery: a real schema
 * error must not silently read as "already recorded".
 *
 * @internal
 */
final class IntegrityError
{
    /**
     * Driver error codes that name a unique-key conflict: MySQL 1062,
     * PostgreSQL `23505` (unique_violation), MS SQL 2601/2627. PDO reports
     * SQLSTATE strings, so the codes are matched as substrings.
     */
    private const array DUPLICATE_CODES = ['1062', '23505', '2601', '2627'];

    private const array DUPLICATE_MESSAGES = [
        'duplicate entry',
        'duplicate key',
        'unique constraint',
        'ora-00001',
    ];

    private const array OTHER_CONSTRAINT_MESSAGES = [
        'foreign key',
        'check constraint',
        'not null constraint',
        'cannot be null',
        'violates',
    ];

    /**
     * The exception chain is walked because drivers differ in which frame
     * carries the driver text — `yiisoft/db` wraps the raw `PDOException`.
     */
    public static function isDuplicateKey(\Throwable $e): bool
    {
        $frames = self::chain($e);

        // A unique-violation message also contains "violates", so the
        // other-constraint pass runs only after no duplicate signature has
        // matched anywhere in the chain.
        foreach ($frames as $frame) {
            if (
                self::matches(self::DUPLICATE_CODES, (string) $frame->getCode())
                || self::matches(self::DUPLICATE_MESSAGES, strtolower($frame->getMessage()))
            ) {
                return true;
            }
        }

        foreach ($frames as $frame) {
            if (self::matches(self::OTHER_CONSTRAINT_MESSAGES, strtolower($frame->getMessage()))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $needles
     */
    private static function matches(array $needles, string $haystack): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<\Throwable>
     */
    private static function chain(\Throwable $e): array
    {
        $frames = [];

        for (
            $current = $e;
            $current !== null && !\in_array($current, $frames, true);
            $current = $current->getPrevious()
        ) {
            $frames[] = $current;
        }

        return $frames;
    }
}
