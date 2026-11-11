<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Phil\RedirectsBundle\Infrastructure\Doctrine\RowReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RowReader::class)]
final class RowReaderTest extends TestCase
{
    public function testEachValueIsReadStrictly(): void
    {
        $read = new RowReader(new SQLitePlatform());
        $row = ['text' => 'a', 'number' => 7, 'digits' => '42', 'negative' => '-1', 'word' => 'x', 'float' => 1.5, 'moment' => '2026-05-01 10:00:00', 'bad' => 'not a date', 'yes' => 1, 'no' => 0, 'none' => null];

        self::assertSame('a', $read->text($row, 'text'));
        self::assertNull($read->text($row, 'number'));
        self::assertNull($read->text($row, 'missing'));
        self::assertSame(7, $read->number($row, 'number'));
        self::assertSame(42, $read->number($row, 'digits'));
        self::assertNull($read->number($row, 'negative'));
        self::assertNull($read->number($row, 'word'));
        self::assertNull($read->number($row, 'float'));
        self::assertNull($read->number($row, 'missing'));
        self::assertEquals(new DateTimeImmutable('2026-05-01 10:00:00'), $read->moment($row, 'moment'));
        self::assertNull($read->moment($row, 'bad'));
        self::assertNull($read->moment($row, 'none'));
        self::assertNull($read->moment($row, 'missing'));
        self::assertTrue($read->flag($row, 'yes'));
        self::assertFalse($read->flag($row, 'no'));
        self::assertFalse($read->flag($row, 'missing'));
    }
}
