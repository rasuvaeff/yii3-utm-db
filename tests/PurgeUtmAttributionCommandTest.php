<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Tests;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Utm\InMemoryUtmAttributionRepository;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Rasuvaeff\Yii3UtmDb\Command\PurgeUtmAttributionCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(PurgeUtmAttributionCommand::class)]
final class PurgeUtmAttributionCommandTest
{
    private ClockInterface $clock;
    private InMemoryUtmAttributionRepository $repository;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new StaticClock(new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')));
        $this->repository = new InMemoryUtmAttributionRepository($this->clock);
        $this->repository->append(self::attribution());
    }

    public function deletesNothingWhenEverythingIsInsideTheWindow(): void
    {
        $output = $this->run([]);

        Assert::string($output)->contains('Deleted 0 row(s)');
        Assert::same($this->repository->countByEntity('user-1'), 1);
    }

    public function deletesRowsOlderThanTheGivenAge(): void
    {
        // the row was recorded "now", so a zero-day window is not enough;
        // move the cut-off past it by asking for a negative age is invalid —
        // instead purge with a repository clock in the future.
        $repository = new InMemoryUtmAttributionRepository(
            new StaticClock(new \DateTimeImmutable('2020-01-01 00:00:00', new \DateTimeZone('UTC'))),
        );
        $repository->append(self::attribution());

        $command = new PurgeUtmAttributionCommand($repository, $this->clock);
        $output = new BufferedOutput();
        $command->run(new ArrayInput(['--older-than' => '30']), $output);

        Assert::string($output->fetch())->contains('Deleted 1 row(s)');
        Assert::same($repository->countByEntity('user-1'), 0);
    }

    public function dryRunTouchesNothing(): void
    {
        $output = $this->run(['--dry-run' => true, '--older-than' => '1']);

        Assert::string($output)->contains('Would delete rows recorded before');
        Assert::same($this->repository->countByEntity('user-1'), 1);
    }

    public function reportsTheCutOffInUtc(): void
    {
        Assert::string($this->run(['--dry-run' => true, '--older-than' => '10']))->contains('2026-07-22T12:00:00+00:00');
    }

    public function usesTheConfiguredDefaultAge(): void
    {
        $command = new PurgeUtmAttributionCommand($this->repository, $this->clock, defaultDays: 7);
        $output = new BufferedOutput();
        $command->run(new ArrayInput(['--dry-run' => true]), $output);

        Assert::string($output->fetch())->contains('2026-07-25T12:00:00+00:00');
    }

    public function rejectsANonPositiveAge(): void
    {
        foreach (['0', '-5', 'ten', '1.5'] as $value) {
            try {
                $this->run(['--older-than' => $value]);
            } catch (\InvalidArgumentException $e) {
                Assert::string($e->getMessage())->contains('positive whole number of days');

                continue;
            }

            Assert::true(false);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function run(array $arguments): string
    {
        $command = new PurgeUtmAttributionCommand($this->repository, $this->clock);
        $output = new BufferedOutput();
        $command->run(new ArrayInput($arguments), $output);

        return $output->fetch();
    }

    private static function attribution(): UtmAttribution
    {
        return new UtmAttribution(
            entityId: 'user-1',
            eventId: 'order-1',
            interactionType: InteractionType::purchase(),
            touchpoint: UtmTouchpoint::of(
                utm: new UtmParameters(source: 'google'),
                occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
            ),
        );
    }
}
