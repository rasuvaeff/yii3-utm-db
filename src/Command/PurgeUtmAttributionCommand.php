<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3UtmDb\Command;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Utm\UtmAttributionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes attribution rows older than N days.
 *
 * The journal is append-only and nothing trims it on its own, so without a
 * schedule for this command the table grows forever — and it holds campaign
 * data tied to an entity identifier, which is exactly the kind of data a
 * retention policy is supposed to bound.
 *
 * Purging is retention, not erasure of one person's data: for that use
 * `deleteByEntity()`.
 *
 * @api
 */
#[AsCommand(name: 'utm:purge', description: 'Delete UTM attribution rows older than N days')]
final class PurgeUtmAttributionCommand extends Command
{
    public function __construct(
        private readonly UtmAttributionRepository $repository,
        private readonly ClockInterface $clock,
        private readonly int $defaultDays = 365,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'older-than',
            'o',
            InputOption::VALUE_REQUIRED,
            'ISO-8601 age interval; rows recorded before this are deleted (for example P90D)',
        );
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the cut-off without deleting');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = $this->clock->now()->sub($this->interval($input->getOption('older-than')));

        if ($input->getOption('dry-run') === true) {
            $output->writeln(\sprintf(
                'Would delete %d row(s) recorded before %s',
                $this->repository->countOlderThan($before),
                $before->format(\DateTimeInterface::ATOM),
            ));

            return Command::SUCCESS;
        }

        $output->writeln(\sprintf(
            'Deleted %d row(s) recorded before %s',
            $this->repository->purgeOlderThan($before),
            $before->format(\DateTimeInterface::ATOM),
        ));

        return Command::SUCCESS;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function interval(mixed $option): \DateInterval
    {
        if ($option === null) {
            return new \DateInterval('P' . ($this->defaultDays > 0 ? $this->defaultDays : 1) . 'D');
        }

        if (!\is_string($option) || $option === '') {
            throw new \InvalidArgumentException('Option --older-than must be a positive ISO-8601 interval');
        }

        try {
            $interval = new \DateInterval($option);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Option --older-than must be a positive ISO-8601 interval');
        }

        $epoch = new \DateTimeImmutable('@0');

        if ($epoch->add($interval) <= $epoch) {
            throw new \InvalidArgumentException('Option --older-than must be a positive ISO-8601 interval');
        }

        return $interval;
    }
}
