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
    }
}
