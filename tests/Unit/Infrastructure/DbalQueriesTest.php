<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Phil\RedirectsBundle\Domain\Model\Redirect;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use Phil\RedirectsBundle\Infrastructure\Doctrine\DbalNotFoundLog;
use Phil\RedirectsBundle\Infrastructure\Doctrine\DbalRedirectRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The statements the stores send, and the rows as a driver may return them: numbers as strings of digits, and values
 * that no longer read.
 */
#[CoversClass(DbalRedirectRepository::class)]
#[CoversClass(DbalNotFoundLog::class)]
final class DbalQueriesTest extends TestCase
{
    private const array ROW = [
        'id' => '7', 'host' => null, 'kind' => 'exact', 'source' => '/a', 'target' => '/b', 'status' => '302', 'query_policy' => 'keep',
        'locale' => 'fr', 'enabled' => '1', 'hits' => '12', 'last_hit_at' => '2026-05-02 10:00:00', 'created_at' => '2026-05-01 10:00:00', 'updated_at' => '2026-05-01 11:00:00',
    ];

    private const array ENTRY = [
        'id' => '3', 'host' => 'h.example', 'path' => '/a', 'hits' => '5', 'first_seen_at' => '2026-05-01 10:00:00', 'last_seen_at' => '2026-05-02 10:00:00', 'referrer_host' => 'r.example',
    ];

    public function testARedirectIsFoundByItsIdentifier(): void
    {
        $connection = $this->connection();
        $connection->expects($this->once())->method('fetchAssociative')
            ->with('SELECT id, host, kind, source, target, status, query_policy, locale, enabled, hits, last_hit_at, created_at, updated_at FROM phil_redirect WHERE id = ?', [7], [ParameterType::INTEGER])
            ->willReturn(self::ROW);

        $redirect = new DbalRedirectRepository($connection)->find(7);

        self::assertNotNull($redirect);
        self::assertSame(7, $redirect->id());
        self::assertSame(12, $redirect->hits());
        self::assertSame(302, $redirect->rule()->status->value);
        self::assertTrue($redirect->isEnabled());
        self::assertEquals(new DateTimeImmutable('2026-05-02 10:00:00'), $redirect->lastHitAt());
    }

    public function testARedirectToLocalhostIsReadOnlyWhenAllowed(): void
    {
        $row = ['target' => 'http://localhost:8000/b'] + self::ROW;
        $connection = self::createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('fetchAssociative')->willReturn($row);

        self::assertNull(new DbalRedirectRepository($connection)->find(7));
        self::assertSame('localhost', new DbalRedirectRepository($connection, allowLocalhost: true)->find(7)?->rule()->target->host);
    }

    public function testEveryRedirectIsReadInTheOrderOfCreation(): void
    {
        $connection = $this->connection();
        $connection->expects($this->once())->method('fetchAllAssociative')
            ->with('SELECT id, host, kind, source, target, status, query_policy, locale, enabled, hits, last_hit_at, created_at, updated_at FROM phil_redirect ORDER BY id')
            ->willReturn([self::ROW, ['kind' => 'fuzzy'] + self::ROW, ['id' => '8'] + self::ROW]);

        self::assertSame([7, 8], array_map(static fn (Redirect $redirect): ?int => $redirect->id(), new DbalRedirectRepository($connection)->all()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadableRedirects(): iterable
    {
        yield 'identifier' => [['id' => 'x']];
        yield 'hits' => [['hits' => null]];
        yield 'created' => [['created_at' => null]];
        yield 'updated' => [['updated_at' => 'garbage']];
        yield 'kind' => [['kind' => null]];
        yield 'status' => [['status' => 'x']];
        yield 'query' => [['query_policy' => 'merge']];
        yield 'source' => [['source' => 'relative']];
    }

    /**
     * @param array<string, mixed> $broken
     */
    #[DataProvider('unreadableRedirects')]
    public function testARedirectRowThatDoesNotReadIsLeftOut(array $broken): void
    {
        $connection = $this->stub();
        $connection->method('fetchAssociative')->willReturn($broken + self::ROW);

        self::assertNull(new DbalRedirectRepository($connection)->find(7));
    }

    public function testAnUpdateAndARemovalAreByIdentifier(): void
    {
        $connection = $this->connection();
        $redirect = new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')), true, new DateTimeImmutable(), 7);
        $connection->expects($this->once())->method('update')->with(
            'phil_redirect',
            self::anything(),
            ['id' => 7],
            ['status' => ParameterType::INTEGER, 'enabled' => ParameterType::BOOLEAN, 'updated_at' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER],
        );
        $connection->expects($this->exactly(2))->method('delete')->with('phil_redirect', ['id' => 7], ['id' => ParameterType::INTEGER])->willReturnOnConsecutiveCalls('1', 0);

        $repository = new DbalRedirectRepository($connection);
        $repository->save($redirect);
        self::assertTrue($repository->remove(7));
        self::assertFalse($repository->remove(7));
    }

    public function testAnInsertGivesEveryType(): void
    {
        $connection = $this->connection();
        $connection->expects($this->once())->method('insert')->with(
            'phil_redirect',
            self::callback(static fn (array $values): bool => 0 === $values['hits'] && null === $values['last_hit_at']),
            ['status' => ParameterType::INTEGER, 'enabled' => ParameterType::BOOLEAN, 'updated_at' => Types::DATETIME_IMMUTABLE, 'hits' => ParameterType::INTEGER, 'last_hit_at' => Types::DATETIME_IMMUTABLE, 'created_at' => Types::DATETIME_IMMUTABLE],
        );
        $connection->method('lastInsertId')->willReturn('9');
        $redirect = new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')), true, new DateTimeImmutable());

        new DbalRedirectRepository($connection)->save($redirect);

        self::assertSame(9, $redirect->id());
    }

    public function testAnEntryIsFoundByItsIdentifier(): void
    {
        $connection = $this->connection();
        $connection->expects($this->once())->method('fetchAssociative')
            ->with('SELECT id, host, path, hits, first_seen_at, last_seen_at, referrer_host FROM phil_redirect_not_found WHERE id = ?', [3], [ParameterType::INTEGER])
            ->willReturn(self::ENTRY);

        $entry = new DbalNotFoundLog($connection)->find(3);

        self::assertNotNull($entry);
        self::assertSame(3, $entry->id);
        self::assertSame(5, $entry->hits);
        self::assertSame('r.example', $entry->referrerHost);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadableEntries(): iterable
    {
        yield 'identifier' => [['id' => null]];
        yield 'host' => [['host' => null]];
        yield 'path' => [['path' => 3]];
        yield 'hits' => [['hits' => '-1']];
        yield 'first' => [['first_seen_at' => null]];
        yield 'last' => [['last_seen_at' => 'garbage']];
    }

    /**
     * @param array<string, mixed> $broken
     */
    #[DataProvider('unreadableEntries')]
    public function testAnEntryThatDoesNotReadIsLeftOut(array $broken): void
    {
        $connection = $this->stub();
        $connection->method('fetchAssociative')->willReturn($broken + self::ENTRY);
        $connection->method('fetchAllAssociative')->willReturn([self::ENTRY, $broken + self::ENTRY, ['id' => '4'] + self::ENTRY]);
        $log = new DbalNotFoundLog($connection);

        self::assertNull($log->find(3));
        self::assertSame([0, 1], array_keys($log->page(0, 10)));
        self::assertSame(4, $log->page(0, 10)[1]->id);
    }

    public function testTheWritesOfTheLogGiveTheirTypes(): void
    {
        $connection = $this->connection();
        $at = new DateTimeImmutable('2026-05-01');
        $connection->method('executeStatement')->willReturn(0);
        $connection->method('fetchOne')->willReturn('0');
        $connection->expects($this->once())->method('insert')->with(
            'phil_redirect_not_found',
            self::anything(),
            ['hits' => ParameterType::INTEGER, 'first_seen_at' => Types::DATETIME_IMMUTABLE, 'last_seen_at' => Types::DATETIME_IMMUTABLE],
        );
        $connection->expects($this->exactly(2))->method('delete')->with('phil_redirect_not_found', ['id' => 3], ['id' => ParameterType::INTEGER])->willReturnOnConsecutiveCalls('1', 0);

        $log = new DbalNotFoundLog($connection);
        $log->record('h.example', '/a', null, $at, 10);
        self::assertTrue($log->remove(3));
        self::assertFalse($log->remove(3));
    }

    public function testAnUpdateReportedAsAStringOfDigitsCounts(): void
    {
        $connection = $this->connection();
        $connection->method('executeStatement')->willReturn('1');
        $connection->expects($this->never())->method('insert');

        new DbalNotFoundLog($connection)->record('h.example', '/a', null, new DateTimeImmutable(), 10);
    }

    /**
     * @return Connection&Stub
     */
    private function stub(): Connection
    {
        $connection = self::createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        return $connection;
    }

    /**
     * @return Connection&MockObject
     */
    private function connection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        return $connection;
    }
}
