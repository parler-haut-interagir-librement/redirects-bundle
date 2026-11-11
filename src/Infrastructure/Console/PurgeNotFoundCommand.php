<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Console;

use DateInterval;
use Override;
use Phil\RedirectsBundle\Domain\Contract\NotFoundLogRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *     bin/console phil:redirects:purge-404 --older-than=90.
 *
 * Removes from the 404 log the paths not requested for some days — the retention of the configuration by default —,
 * for the scheduler to run every night.
 */
#[AsCommand(name: 'phil:redirects:purge-404', description: 'Removes the paths not requested for some days from the 404 log')]
final class PurgeNotFoundCommand extends Command
{
    public function __construct(
        private readonly NotFoundLogRepository $log,
        private readonly ClockInterface $clock,
        private readonly int $retention,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Days without a request after which a path leaves the log', (string) $this->retention);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('older-than');

        if (!\is_string($days) || 1 !== preg_match('/^(?:0|[1-9]\d{0,4})$/D', $days)) {
            $output->writeln('<error>The number of days is a whole number from 0 to 99999.</error>');

            return Command::INVALID;
        }

        $purged = $this->log->purge($this->clock->now()->sub(new DateInterval('P'.$days.'D')));
        $output->writeln(\sprintf('%d path(s) removed from the 404 log.', $purged));

        return Command::SUCCESS;
    }
}
