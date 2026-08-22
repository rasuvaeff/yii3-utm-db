<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Tests;

use Rasuvaeff\Yii3UtmDb\UtmAttributionTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(UtmAttributionTableName::class)]
final class UtmAttributionTableNameTest
{
    public function defaultsToTheDocumentedName(): void
    {
        Assert::same((new UtmAttributionTableName())->value, 'utm_attribution');
        Assert::same(UtmAttributionTableName::DEFAULT, 'utm_attribution');
    }

    #[DataProvider('acceptedNamesProvider')]
    public function acceptsIdentifiers(string $name): void
    {
        Assert::same((new UtmAttributionTableName($name))->value, $name);
    }

    public static function acceptedNamesProvider(): iterable
    {
        yield 'plain' => ['utm_attribution'];

        yield 'prefixed' => ['app_utm_attribution'];

        yield 'schema qualified' => ['analytics.utm_attribution'];

        yield 'leading underscore' => ['_tmp'];
    }

    #[DataProvider('rejectedNamesProvider')]
    public function rejectsAnythingElse(string $name): void
    {
        try {
            new UtmAttributionTableName($name);
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid table name');

            return;
        }

        Assert::true(actual: false);
    }

    public static function rejectedNamesProvider(): iterable
    {
        yield 'empty' => [''];

        yield 'leading digit' => ['1table'];

        yield 'quote' => ['utm";DROP TABLE users;--'];

        yield 'space' => ['utm attribution'];

        yield 'dash' => ['utm-attribution'];

        yield 'two dots' => ['a.b.c'];

        yield 'trailing newline' => ["utm_attribution\n"];
    }

    public function castsToString(): void
    {
        Assert::same((string) new UtmAttributionTableName('analytics.utm'), 'analytics.utm');
    }

    public function indexBaseReplacesTheSchemaSeparator(): void
    {
        Assert::same((new UtmAttributionTableName('analytics.utm'))->forIndexName(), 'analytics_utm');
    }

    public function indexBaseLeavesRoomForPrefixAndSuffix(): void
    {
        $long = \str_repeat('a', 60);

        Assert::same(\strlen((new UtmAttributionTableName($long))->forIndexName()), 40);
    }
}
