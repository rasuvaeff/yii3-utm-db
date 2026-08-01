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
            'Age in whole days; rows recorded before this are deleted',
        );
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the cut-off without deleting');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var mixed $olderThan */
        $olderThan = $input->getOption('older-than');
        $before = $this->clock->now()->modify(\sprintf('-%d days', $this->days($olderThan)));

        if ($input->getOption('dry-run') === true) {
            $output->writeln(\sprintf('Would delete rows recorded before %s', $before->format(\DateTimeInterface::ATOM)));

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
     * @return int<1, max>
     */
    private function days(mixed $option): int
    {
        if ($option === null) {
            return $this->defaultDays > 0 ? $this->defaultDays : 1;
        }

        if (!\is_string($option) || \preg_match('/^\d+\z/', $option) !== 1) {
            throw new \InvalidArgumentException('Option --older-than must be a positive whole number of days');
        }

        $days = (int) $option;

        if ($days < 1) {
            throw new \InvalidArgumentException('Option --older-than must be a positive whole number of days');
        }

        return $days;
    }
}
