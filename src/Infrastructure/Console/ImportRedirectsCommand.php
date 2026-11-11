<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Console;

use Override;
use Phil\RedirectsBundle\Application\Csv\RedirectCsv;
use Phil\RedirectsBundle\Application\Redirects;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *     bin/console phil:redirects:import redirects.csv --update --dry-run.
 *
 * Imports a CSV file of redirects, each line through the checks of a save; a refused line is reported with its
 * reason and the others are imported. The command fails when a line was refused.
 */
#[AsCommand(name: 'phil:redirects:import', description: 'Imports redirects from a CSV file')]
final class ImportRedirectsCommand extends Command
{
    public function __construct(
        private readonly Redirects $redirects,
        private readonly RedirectCsv $csv,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'The CSV file')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Update a redirect whose source is already there, rather than skip the line')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check every line and write nothing');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        if (!\is_string($file) || !is_file($file) || !is_readable($file)) {
            $output->writeln('<error>The file cannot be read.</error>');

            return Command::INVALID;
        }

        $report = $this->redirects->import($this->csv->read((string) file_get_contents($file)), true === $input->getOption('update'), true === $input->getOption('dry-run'));

        foreach ($report->rejected as $line => $reason) {
            $output->writeln(\sprintf('<error>Line %d: %s</error>', $line, $reason));
        }

        foreach ($report->skipped as $line) {
            $output->writeln(\sprintf('Line %d: skipped, the source is already there.', $line));
        }

        $output->writeln(\sprintf('%s%d created, %d updated, %d skipped, %d refused.', $report->dryRun ? 'Dry run: ' : '', $report->created, $report->updated, \count($report->skipped), \count($report->rejected)));

        return $report->isClean() ? Command::SUCCESS : Command::FAILURE;
    }
}
