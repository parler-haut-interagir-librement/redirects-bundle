<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Phil\RedirectsBundle\Application\Csv\RedirectCsv;
use Phil\RedirectsBundle\Domain\Model\Redirect;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use Phil\RedirectsBundle\Infrastructure\Console\CheckRedirectsCommand;
use Phil\RedirectsBundle\Infrastructure\Console\ExportRedirectsCommand;
use Phil\RedirectsBundle\Infrastructure\Console\ImportRedirectsCommand;
use Phil\RedirectsBundle\Infrastructure\Console\PurgeNotFoundCommand;
use Phil\RedirectsBundle\Tests\Unit\Fixture\AdminKit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ImportRedirectsCommand::class)]
#[CoversClass(ExportRedirectsCommand::class)]
#[CoversClass(CheckRedirectsCommand::class)]
#[CoversClass(PurgeNotFoundCommand::class)]
final class CommandsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/phil-redirects-commands-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory.'/*') as $file) {
            if (\is_string($file)) {
                unlink($file);
            }
        }

        rmdir($this->directory);
    }

    public function testAFileIsImported(): void
    {
        $kit = new AdminKit();
        $kit->redirects->create(new RedirectRule(SourcePattern::exact('/kept'), Target::url('/x')));
        file_put_contents($this->directory.'/in.csv', "source,target\n/a,/b\n/kept,/y\n");
        $tester = new CommandTester(new ImportRedirectsCommand($kit->redirects, new RedirectCsv()));

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $this->directory.'/in.csv']));
        self::assertStringContainsString('Line 3: skipped, the source is already there.', $tester->getDisplay());
        self::assertStringContainsString('1 created, 0 updated, 1 skipped, 0 refused.', $tester->getDisplay());
        self::assertCount(2, $kit->repository->all());

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $this->directory.'/in.csv', '--update' => true, '--dry-run' => true]));
        self::assertStringContainsString('Dry run: 0 created, 2 updated, 0 skipped, 0 refused.', $tester->getDisplay());
        self::assertSame('/x', $kit->redirects->get(1)->rule()->target->url);

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $this->directory.'/in.csv', '--update' => true]));
        self::assertSame('/y', $kit->redirects->get(1)->rule()->target->url);
    }

    public function testARefusedLineFailsTheImport(): void
    {
        $kit = new AdminKit();
        file_put_contents($this->directory.'/in.csv', "source,target\nbad,/b\n/c,/d\n");
        $tester = new CommandTester(new ImportRedirectsCommand($kit->redirects, new RedirectCsv()));

        self::assertSame(Command::FAILURE, $tester->execute(['file' => $this->directory.'/in.csv']));
        self::assertStringContainsString('Line 2: A source path starts with one "/".', $tester->getDisplay());
        self::assertCount(1, $kit->repository->all());
    }

    public function testAFileThatCannotBeRead(): void
    {
        $tester = new CommandTester(new ImportRedirectsCommand(new AdminKit()->redirects, new RedirectCsv()));

        self::assertSame(Command::INVALID, $tester->execute(['file' => $this->directory.'/missing.csv']));
        self::assertStringContainsString('The file cannot be read.', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['file' => $this->directory]));
    }

    public function testTheRedirectsAreExportedToAFileOrTheOutput(): void
    {
        $kit = new AdminKit();
        $kit->redirects->create(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')));

        $tester = new CommandTester(new ExportRedirectsCommand($kit->repository, new RedirectCsv()));
        $csv = "source,target,status,kind,host,query,locale,enabled,hits,last_hit_at\n/a,/b,301,exact,,drop,,1,0,\n";

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame($csv, $tester->getDisplay(true));

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $this->directory.'/out.csv']));
        self::assertSame($csv, file_get_contents($this->directory.'/out.csv'));
        self::assertStringContainsString('1 redirect(s) exported.', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $this->directory.'/out.csv']));
        self::assertSame(Command::INVALID, $tester->execute(['file' => $this->directory.'/missing/out.csv']));
        self::assertStringContainsString('The file cannot be written.', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['file' => $this->directory]));
        chmod($this->directory.'/out.csv', 0o444);

        // A user who may write anyway, root in a container, cannot meet this refusal.
        if (!is_writable($this->directory.'/out.csv')) {
            self::assertSame(Command::INVALID, $tester->execute(['file' => $this->directory.'/out.csv']));
        }

        chmod($this->directory.'/out.csv', 0o644);
    }

    public function testTheCheck(): void
    {
        $kit = new AdminKit();
        $tester = new CommandTester(new CheckRedirectsCommand($kit->redirects));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Every redirect passes.', $tester->getDisplay());

        $kit->repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('https://gone.example/')), true, new DateTimeImmutable()));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('#1 /a: The host "gone.example" is not one a redirect may send to.', $tester->getDisplay());
        self::assertStringContainsString('1 redirect(s) to fix.', $tester->getDisplay());
    }

    public function testThePurge(): void
    {
        $kit = new AdminKit();
        $tester = new CommandTester(new PurgeNotFoundCommand($kit->log, $kit->clock, 90));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('7 path(s) removed from the 404 log.', $tester->getDisplay());
        self::assertEquals(new DateTimeImmutable('2026-01-31 10:00:00'), $kit->log->purgedBefore);

        self::assertSame(Command::SUCCESS, $tester->execute(['--older-than' => '0']));
        self::assertEquals($kit->clock->now(), $kit->log->purgedBefore);

        self::assertSame(Command::SUCCESS, $tester->execute(['--older-than' => '99999']));

        foreach (['-1', '01', 'x', '100000', '', "90\n"] as $days) {
            self::assertSame(Command::INVALID, $tester->execute(['--older-than' => $days]));
            self::assertStringContainsString('The number of days is a whole number from 0 to 99999.', $tester->getDisplay());
        }
    }
}
