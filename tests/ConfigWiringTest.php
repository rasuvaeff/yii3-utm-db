<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Tests;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\Yii3Utm\UtmAttributionRepository;
use Rasuvaeff\Yii3UtmDb\Command\PurgeUtmAttributionCommand;
use Rasuvaeff\Yii3UtmDb\DbUtmAttributionRepository;
use Rasuvaeff\Yii3UtmDb\UtmAttributionTableName;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\Log\SimpleLogger;

/**
 * `config/di.php` and `config/params.php` are outside `src`, so neither cs,
 * psalm nor the rest of the suite reads them.
 *
 * The key assertion is the merge rule: this package binds the swappable
 * repository and the core does not, so installing both never produces
 * `Duplicate key` from `yiisoft/config`.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function bindsTheRepositoryAndItsDependencies(): void
    {
        $container = $this->container();

        Assert::instanceOf($container->get(UtmAttributionTableName::class), UtmAttributionTableName::class);
        Assert::instanceOf($container->get(UtmAttributionRepository::class), DbUtmAttributionRepository::class);
        Assert::instanceOf($container->get(PurgeUtmAttributionCommand::class), PurgeUtmAttributionCommand::class);
    }

    /**
     * The one-source rule, checked against the real core config rather than
     * from memory: no key may be defined by both packages.
     */
    public function shareNoKeysWithTheCore(): void
    {
        $core = \array_keys($this->coreDefinitions());
        $backend = \array_keys($this->definitions());

        Assert::same(\array_values(\array_intersect($core, $backend)), []);
        Assert::true(\in_array(UtmAttributionRepository::class, $backend, strict: true));
        Assert::false(\in_array(UtmAttributionRepository::class, $core, strict: true));
    }

    public function shareNoParamKeysWithTheCore(): void
    {
        $core = \array_keys($this->coreParams());
        $backend = \array_keys($this->params());

        Assert::same(\array_values(\array_intersect($core, $backend)), []);
    }

    public function tablePrefixIsAppliedToTheSingleSourceOfTruth(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-utm-db']['table_prefix'] = 'app_';

        $container = $this->container($params);

        Assert::same($container->get(UtmAttributionTableName::class)->value, 'app_utm_attribution');
    }

    /**
     * The container below binds no PSR-3 logger on purpose. The repository only
     * *reports* a malformed row, so an application without a logger must still
     * receive a working repository instead of a container error — and one with
     * a logger must not need any extra configuration to get the reports.
     */
    public function resolvesTheRepositoryWithAndWithoutALoggerBinding(): void
    {
        $without = $this->container()->get(UtmAttributionRepository::class);

        Assert::instanceOf($without, DbUtmAttributionRepository::class);
        Assert::instanceOf($this->repositoryProperty($without, 'logger'), NullLogger::class);

        $withLogger = $this->container(extra: [
            LoggerInterface::class => static fn(): LoggerInterface => new SimpleLogger(),
        ]);

        $with = $withLogger->get(UtmAttributionRepository::class);

        Assert::instanceOf($with, DbUtmAttributionRepository::class);
        Assert::instanceOf($this->repositoryProperty($with, 'logger'), SimpleLogger::class);
    }

    /**
     * The parameter is validated and injected like `table_prefix` above: the
     * configured value reaches the constructor, not just the container.
     */
    public function passesTheConfiguredPurgeBatchSizeThrough(): void
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-utm-db']['purgeBatchSize'] = 42;

        $repository = $this->container($params)->get(UtmAttributionRepository::class);

        Assert::same($this->repositoryProperty($repository, 'purgeBatchSize'), 42);
    }

    private function repositoryProperty(object $repository, string $name): mixed
    {
        $property = new \ReflectionProperty(DbUtmAttributionRepository::class, $name);

        return $property->getValue($repository);
    }

    public function registersTheConsoleCommand(): void
    {
        Assert::same(
            $this->params()['yiisoft/yii-console']['commands']['utm:purge'],
            PurgeUtmAttributionCommand::class,
        );
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    private function definitions(?array $params = null): array
    {
        $params ??= $this->params();

        /** @var array<string, mixed> $definitions */
        $definitions = require \dirname(__DIR__) . '/config/di.php';

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    private function coreDefinitions(): array
    {
        $params = require \dirname(__DIR__) . '/vendor/rasuvaeff/yii3-utm/config/params.php';

        /** @var array<string, mixed> $definitions */
        $definitions = require \dirname(__DIR__) . '/vendor/rasuvaeff/yii3-utm/config/di.php';

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    private function params(): array
    {
        /** @var array<string, mixed> $params */
        $params = require \dirname(__DIR__) . '/config/params.php';

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    private function coreParams(): array
    {
        /** @var array<string, mixed> $params */
        $params = require \dirname(__DIR__) . '/vendor/rasuvaeff/yii3-utm/config/params.php';

        return $params;
    }

    /**
     * @param array<string, mixed>|null $params
     * @param array<string, mixed> $extra
     */
    private function container(?array $params = null, array $extra = []): Container
    {
        // array_replace, not `+`: $extra is an override list, and the union
        // operator keeps the left operand on collision, so a key already
        // defined by config/di.php would silently ignore it.
        $definitions = \array_replace($this->definitions($params), $extra);
        $definitions[ClockInterface::class] = static fn(): ClockInterface
            => new StaticClock(new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')));
        $definitions[ConnectionInterface::class] = static fn(): ConnectionInterface
            => new Connection(new Driver('sqlite::memory:'), new SchemaCache(new ArrayCache()));

        return new Container(ContainerConfig::create()->withDefinitions($definitions));
    }
}
