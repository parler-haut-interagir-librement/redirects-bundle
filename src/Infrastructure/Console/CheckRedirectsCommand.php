<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Console;

use Override;
use Phil\RedirectsBundle\Application\Redirects;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *     bin/console phil:redirects:check.
 *
 * Lists the stored redirects that would be refused if they were saved today — a loop or a chain made since with the
 * redirects of a provider, a target whose host is no longer allowed, a regular expression while they are off — and
 * fails when there is one, for a scheduled check or a deployment.
 */
#[AsCommand(name: 'phil:redirects:check', description: 'Checks the stored redirects for loops, chains and targets no longer allowed')]
final class CheckRedirectsCommand extends Command
{
    public function __construct(
        private readonly Redirects $redirects,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $findings = $this->redirects->check();

        foreach ($findings as $finding) {
            $output->writeln(\sprintf('<error>#%d %s: %s</error>', $finding->id, $finding->source, $finding->message));
        }

        $output->writeln([] === $findings ? 'Every redirect passes.' : \sprintf('%d redirect(s) to fix.', \count($findings)));

        return [] === $findings ? Command::SUCCESS : Command::FAILURE;
    }
}
