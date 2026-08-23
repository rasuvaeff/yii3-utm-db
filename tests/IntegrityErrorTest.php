<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Tests;

use Rasuvaeff\Yii3UtmDb\IntegrityError;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Db\Exception\IntegrityException;

#[Test]
#[Covers(IntegrityError::class)]
final class IntegrityErrorTest
{
    /**
     * In production the driver code lives on the wrapped `PDOException`
     * (`yiisoft/db` rethrows it), so the code-bearing cases carry it there.
     *
     * @return iterable<string, array{0: IntegrityException}>
     */
    public static function duplicateProvider(): iterable
    {
        yield 'mysql 1062' => [new IntegrityException(
            'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'utm:order-1\' for key \'uq_utm_touch_dedupe\'',
        )];

        yield 'mysql code only' => [new IntegrityException(
            'some entry conflict',
            previous: new \PDOException('Duplicate entry', 1062),
        )];

        yield 'postgres unique_violation' => [new IntegrityException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "uq_utm_touch_dedupe"',
        )];

        yield 'sqlite unique' => [new IntegrityException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: utm_attribution.dedupe_key',
        )];

        yield 'mssql 2627' => [new IntegrityException(
            'Violation of UNIQUE KEY constraint \'uq_utm_touch_dedupe\'. Cannot insert duplicate key in object \'dbo.utm_attribution\'.',
            previous: new \PDOException('2627', 2627),
        )];

        yield 'oracle ora-00001' => [new IntegrityException(
            'ORA-00001: unique constraint (APP.UQ_UTM_TOUCH_DEDUPE) violated',
        )];

        yield 'duplicate found in the wrapped driver frame' => [new IntegrityException(
            'failed to execute SQL',
            previous: new \PDOException(
                'SQLSTATE[23505]: ERROR: duplicate key value violates unique constraint "uq_utm_touch_dedupe"',
                23505,
            ),
        )];

        yield 'unknown driver message stays on the legacy path' => [new IntegrityException(
            'exotic driver exploded while checking the key',
        )];
    }

    #[DataProvider('duplicateProvider')]
    public function recognisesDuplicateKeyViolations(IntegrityException $e): void
    {
        Assert::true(IntegrityError::isDuplicateKey($e));
    }

    /**
     * @return iterable<string, array{0: IntegrityException}>
     */
    public static function otherConstraintProvider(): iterable
    {
        yield 'mysql foreign key' => [new IntegrityException(
            'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`app`.`utm_attribution`, CONSTRAINT `fk_entity` FOREIGN KEY (`entity_id`) REFERENCES `entity` (`id`))',
        )];

        yield 'sqlite foreign key' => [new IntegrityException('FOREIGN KEY constraint failed')];

        yield 'mysql check' => [new IntegrityException(
            'SQLSTATE[3819]: Check constraint is violated: Check constraint `ck_weight` is violated.',
        )];

        yield 'postgres check' => [new IntegrityException(
            'SQLSTATE[23514]: Check violation: 7 ERROR: new row for relation "utm_attribution" violates check constraint "ck_weight"',
        )];

        yield 'mysql not null' => [new IntegrityException(
            "SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'entity_id' cannot be null",
        )];

        yield 'sqlite not null' => [new IntegrityException(
            'NOT NULL constraint failed: utm_attribution.entity_id',
        )];

        yield 'postgres not null' => [new IntegrityException(
            'SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column "entity_id" of relation "utm_attribution" violates not-null constraint',
        )];

        yield 'other violation in the wrapped driver frame' => [new IntegrityException(
            'failed to execute SQL',
            previous: new \PDOException('FOREIGN KEY constraint failed'),
        )];
    }

    #[DataProvider('otherConstraintProvider')]
    public function rethrowsRecognisedNonDuplicateConstraints(IntegrityException $e): void
    {
        Assert::false(IntegrityError::isDuplicateKey($e));
    }

    /**
     * PostgreSQL names the constraint kind in one sentence — the unique
     * signature must win over the shared "violates" wording.
     */
    public function uniqueSignatureWinsOverTheSharedViolatesWording(): void
    {
        Assert::true(IntegrityError::isDuplicateKey(new IntegrityException(
            'ERROR: duplicate key value violates unique constraint "uq_utm_touch_dedupe"',
        )));
    }

    /**
     * A cyclic `previous` chain must not hang the walk; the first cycle edge
     * simply ends it.
     */
    public function survivesACyclicPreviousChain(): void
    {
        $inner = new IntegrityException('FOREIGN KEY constraint failed');
        $outer = new IntegrityException('outer', previous: $inner);

        $previous = new \ReflectionProperty(\Exception::class, 'previous');
        $previous->setAccessible(true);
        $previous->setValue($inner, $outer);

        Assert::false(IntegrityError::isDuplicateKey($outer));
    }
}
