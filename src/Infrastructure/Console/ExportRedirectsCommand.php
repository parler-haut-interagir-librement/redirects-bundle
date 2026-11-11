<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Console;

use Override;
use Phil\RedirectsBundle\Application\Csv\RedirectCsv;
use Phil\RedirectsBundle\Domain\Contract\RedirectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *     bin/console phil:redirects:export redirects.csv.
 *
 * Writes every redirect of the administration as CSV, to the file given or to the standard output.
 */
#[AsCommand(name: 'phil:redirects:export', description: 'Exports the redirects as CSV')]
final class ExportRedirectsCommand extends Command
{
    public function __construct(
        private readonly RedirectRepository $repository,
        private readonly RedirectCsv $csv,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'The file to write; the standard output when none is given');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        if (\is_string($file) && (!is_dir(\dirname($file)) || file_exists($file) && (!is_file($file) || !is_writable($file)))) {
            $output->writeln('<error>The file cannot be written.</error>');

            return Command::INVALID;
        }

        $redirects = $this->repository->all();
        $csv = $this->csv->write($redirects);

        if (!\is_string($file)) {
            $output->write($csv, false, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        file_put_contents($file, $csv);
        $output->writeln(\sprintf('%d redirect(s) exported.', \count($redirects)));

        return Command::SUCCESS;
    }
}
