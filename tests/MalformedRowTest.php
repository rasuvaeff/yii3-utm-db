<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Tests;

use Rasuvaeff\Yii3Utm\Exception\UtmException;
use Rasuvaeff\Yii3UtmDb\Exception\MalformedRow;
use Rasuvaeff\Yii3UtmDb\Exception\UtmDbException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(MalformedRow::class)]
final class MalformedRowTest
{
    public function identifiesTheColumnAndExceptionFamily(): void
    {
        $exception = MalformedRow::column('recorded_at', 'is invalid');

        Assert::instanceOf($exception, UtmDbException::class);
        Assert::instanceOf($exception, UtmException::class);
        Assert::same($exception->getMessage(), 'Malformed attribution row: column "recorded_at" is invalid');
        Assert::null($exception->rowId);
    }

    public function forRowAppendsTheRowIdWithoutLosingTheReason(): void
    {
        $exception = MalformedRow::column('recorded_at', 'is invalid')->forRow('4711');

        Assert::same(
            $exception->getMessage(),
            'Malformed attribution row: column "recorded_at" is invalid (row id 4711)',
        );
        Assert::same($exception->rowId, '4711');
        Assert::instanceOf($exception, UtmDbException::class);
    }

    /**
     * The class is `@api` and was released with the inherited
     * `\RuntimeException` signature, so `$code` and `$previous` still work —
     * and `forRow()` carries both over instead of quietly dropping them.
     */
    public function keepsTheInheritedConstructorAndCarriesItThroughForRow(): void
    {
        $cause = new \RuntimeException('driver said no');
        $exception = new MalformedRow('broken', 17, $cause);

        Assert::same($exception->getCode(), 17);
        Assert::same($exception->getPrevious(), $cause);
        Assert::null($exception->rowId);

        $named = $exception->forRow('99');

        Assert::same($named->getMessage(), 'broken (row id 99)');
        Assert::same($named->getCode(), 17);
        Assert::same($named->getPrevious(), $cause);
        Assert::same($named->rowId, '99');
    }
}
