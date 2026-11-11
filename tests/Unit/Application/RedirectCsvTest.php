<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Application;

use DateTimeImmutable;
use Phil\RedirectsBundle\Application\Csv\RedirectCsv;
use Phil\RedirectsBundle\Application\RedirectDraft;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\Model\Redirect;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedirectCsv::class)]
final class RedirectCsvTest extends TestCase
{
    public function testTheRedirectsAreWrittenWithAHeader(): void
    {
        $redirects = [
            new Redirect(new RedirectRule(SourcePattern::prefix('/a', 'h.example'), Target::url('/b')), true, new DateTimeImmutable(), 1, 3, new DateTimeImmutable('2026-05-01T10:00:00+00:00')),
            new Redirect(new RedirectRule(SourcePattern::regex('-x'), Target::none(), RedirectStatus::Gone, locale: 'fr'), false, new DateTimeImmutable(), 2),
        ];

        self::assertSame(
            "source,target,status,kind,host,query,locale,enabled,hits,last_hit_at\n/a,/b,301,prefix,h.example,drop,,1,3,2026-05-01T10:00:00+00:00\n'-x,,410,regex,,drop,fr,0,0,\n",
            $this->written($redirects),
        );
    }

    public function testWhatIsWrittenIsReadBack(): void
    {
        $redirect = new Redirect(new RedirectRule(SourcePattern::regex('=a'), Target::url('/b'), locale: 'fr'), false, new DateTimeImmutable(), 1);
        $lines = iterator_to_array(new RedirectCsv()->read($this->written([$redirect])));

        self::assertEquals([2 => new RedirectDraft('=a', '/b', '301', 'regex', '', 'drop', 'fr', false)], $lines);
    }

    public function testTheColumnsAreReadByNameWithDefaults(): void
    {
        $csv = "\u{FEFF}Target , SOURCE,extra\n/b,/a,x\n\n,/c\n/d,/e,y,z\n";
        $lines = iterator_to_array(new RedirectCsv()->read($csv));

        self::assertEquals([
            2 => new RedirectDraft('/a', '/b'),
            4 => new RedirectDraft('/c'),
            5 => new RedirectDraft('/e', '/d'),
        ], $lines);
    }

    public function testBlankCellsTakeTheirDefaults(): void
    {
        $lines = iterator_to_array(new RedirectCsv()->read("source,status,kind,query,enabled\n/a, , , ,\n/b,302,prefix,keep,no\n/c,,,,false\n/d,,,,OFF\n/e,,,,0\n/f,,,,yes\n"));

        self::assertEquals(new RedirectDraft('/a', '', '301', 'exact', '', 'drop', '', true), $lines[2]);
        self::assertEquals(new RedirectDraft('/b', '', '302', 'prefix', '', 'keep', '', false), $lines[3]);
        self::assertFalse($lines[4] instanceof RedirectDraft && $lines[4]->enabled);
        self::assertFalse($lines[5] instanceof RedirectDraft && $lines[5]->enabled);
        self::assertFalse($lines[6] instanceof RedirectDraft && $lines[6]->enabled);
        self::assertTrue($lines[7] instanceof RedirectDraft && $lines[7]->enabled);
    }

    public function testAMissingColumnIsEnabled(): void
    {
        $lines = iterator_to_array(new RedirectCsv()->read("source\n/a\n"));

        self::assertTrue($lines[2] instanceof RedirectDraft && $lines[2]->enabled);
    }

    public function testAFileWithoutASourceColumnIsRefused(): void
    {
        foreach (['target\n/a\n', ''] as $csv) {
            $lines = iterator_to_array(new RedirectCsv()->read(str_replace('\n', "\n", $csv)));

            self::assertCount(1, $lines);
            self::assertInstanceOf(InvalidRedirect::class, $lines[1]);
            self::assertSame('csv_header', $lines[1]->reason);
        }
    }

    public function testTheLinesAndCellsAreBounded(): void
    {
        $lines = iterator_to_array(new RedirectCsv(2)->read("source\n/a\n/b\n/c\n/d\n"));

        self::assertCount(3, $lines);
        self::assertInstanceOf(RedirectDraft::class, $lines[3]);
        self::assertInstanceOf(InvalidRedirect::class, $lines[4]);
        self::assertSame('csv_lines', $lines[4]->reason);

        $long = '/'.str_repeat('a', Target::MAX_LENGTH + 16);
        $cells = iterator_to_array(new RedirectCsv()->read("source\n".$long."\n".substr($long, 1)."\n"));
        self::assertInstanceOf(InvalidRedirect::class, $cells[2]);
        self::assertSame('csv_cell', $cells[2]->reason);
        self::assertInstanceOf(RedirectDraft::class, $cells[3]);
    }

    public function testTheLineOfTheLimitIsTheLineOfTheFile(): void
    {
        $lines = iterator_to_array(new RedirectCsv(1)->read("source\n/a\n\n/b\n"));

        self::assertSame([2, 4], array_keys($lines));
        self::assertInstanceOf(InvalidRedirect::class, $lines[4]);
        self::assertSame(['%max%' => '1'], $lines[4]->parameters);
    }

    public function testTheCellsOfEveryColumnAreRead(): void
    {
        $lines = iterator_to_array(new RedirectCsv()->read("source,target,status,kind,host,query,locale,enabled\n/a,/b,302,prefix,h.example,keep,fr, 0 \n"));

        self::assertEquals([2 => new RedirectDraft('/a', '/b', '302', 'prefix', 'h.example', 'keep', 'fr', false)], $lines);
    }

    public function testATooLongCellNamesTheLimit(): void
    {
        $lines = iterator_to_array(new RedirectCsv()->read("source\n/".str_repeat('a', 3000)."\n"));

        self::assertInstanceOf(InvalidRedirect::class, $lines[2]);
        self::assertSame(['%max%' => '2064'], $lines[2]->parameters);
    }

    public function testAQuoteIsRemovedOnlyBeforeAFormula(): void
    {
        $lines = iterator_to_array(new RedirectCsv()->read("source,target,locale\n'+a,'/b,'\n'@a,'-b,x\n"));

        self::assertInstanceOf(RedirectDraft::class, $lines[2]);
        self::assertSame('+a', $lines[2]->source);
        self::assertSame("'/b", $lines[2]->target);
        self::assertSame("'", $lines[2]->locale);
        self::assertInstanceOf(RedirectDraft::class, $lines[3]);
        self::assertSame('@a', $lines[3]->source);
        self::assertSame('-b', $lines[3]->target);
    }

    /**
     * @param list<Redirect> $redirects
     */
    private function written(array $redirects): string
    {
        return new RedirectCsv()->write($redirects);
    }
}
