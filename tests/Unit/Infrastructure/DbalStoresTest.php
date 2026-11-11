<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Phil\RedirectsBundle\Domain\Enum\QueryPolicy;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Model\Redirect;
use Phil\RedirectsBundle\Domain\ValueObject\NotFoundEntry;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use Phil\RedirectsBundle\Infrastructure\Doctrine\DbalNotFoundLog;
use Phil\RedirectsBundle\Infrastructure\Doctrine\DbalRedirectRepository;
use Phil\RedirectsBundle\Infrastructure\Doctrine\RedirectSchemaListener;
use Phil\RedirectsBundle\Infrastructure\Doctrine\RedirectTables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two stores on SQLite in memory: what is kept and read back, the searches, the pages and their order, the
 * counters, the purge, and the rows that no longer read.
 */
#[CoversClass(DbalRedirectRepository::class)]
#[CoversClass(DbalNotFoundLog::class)]
#[CoversClass(RedirectTables::class)]
#[CoversClass(RedirectSchemaListener::class)]
final class DbalStoresTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (RedirectTables::configure(Schema::editor()->create())->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function testARedirectIsInsertedThenUpdated(): void
    {
        $repository = new DbalRedirectRepository($this->connection);
        $created = new DateTimeImmutable('2026-01-01 10:00:00');
        $redirect = new Redirect(new RedirectRule(SourcePattern::wildcard('/a/*', 'h.example'), Target::route('post', ['slug' => '$1']), RedirectStatus::Found, QueryPolicy::Keep, 'fr'), false, $created);

        $repository->save($redirect);
        self::assertSame(1, $redirect->id());

        $read = $repository->find(1);
        self::assertNotNull($read);
        self::assertEquals($redirect->rule(), $read->rule());
        self::assertFalse($read->isEnabled());
        self::assertEquals($created, $read->createdAt());
        self::assertEquals($created, $read->updatedAt());
        self::assertSame(0, $read->hits());
        self::assertNull($read->lastHitAt());

        $updated = new DateTimeImmutable('2026-02-01 10:00:00');
        $read->change(new RedirectRule(SourcePattern::exact('/b'), Target::none(), RedirectStatus::Gone), true, $updated);
        $repository->save($read);

        $again = $repository->find(1);
        self::assertNotNull($again);
        self::assertTrue($again->rule()->target->isNone());
        self::assertNull($this->connection->fetchOne('SELECT target FROM phil_redirect'));
        self::assertTrue($again->isEnabled());
        self::assertEquals($updated, $again->updatedAt());
        self::assertEquals($created, $again->createdAt());
        self::assertSame(1, $repository->count());
        self::assertNull($repository->find(2));
    }

    public function testTheListsTheirOrderAndTheSearch(): void
    {
        $repository = new DbalRedirectRepository($this->connection);

        foreach (['/a_b', '/axb', '/c', '/d%'] as $source) {
            $repository->save(new Redirect(new RedirectRule(SourcePattern::exact($source), Target::url('/target'.$source)), true, new DateTimeImmutable()));
        }

        self::assertSame(['/a_b', '/axb', '/c', '/d%'], array_map(static fn (Redirect $redirect): string => $redirect->rule()->source->path, $repository->all()));
        self::assertSame(['/d%', '/c'], array_map(static fn (Redirect $redirect): string => $redirect->rule()->source->path, $repository->page(0, 2)));
        self::assertSame(['/axb'], array_map(static fn (Redirect $redirect): string => $redirect->rule()->source->path, $repository->page(2, 1)));
        self::assertSame(['/a_b'], array_map(static fn (Redirect $redirect): string => $redirect->rule()->source->path, $repository->page(0, 10, 'a_')));
        self::assertSame(1, $repository->count('a_'));
        self::assertSame(1, $repository->count('d%'));
        self::assertSame(4, $repository->count('target'));
        self::assertSame(0, $repository->count('!'));
        self::assertSame(4, $repository->count());
    }

    public function testARemoval(): void
    {
        $repository = new DbalRedirectRepository($this->connection);
        $repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')), true, new DateTimeImmutable()));

        self::assertTrue($repository->remove(1));
        self::assertFalse($repository->remove(1));
        self::assertSame([], $repository->all());
    }

    public function testAHitIsCountedAndItsMomentThrottled(): void
    {
        $repository = new DbalRedirectRepository($this->connection);
        $repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')), true, new DateTimeImmutable()));
        $repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/c'), Target::url('/d')), true, new DateTimeImmutable()));

        $first = new DateTimeImmutable('2026-05-01 10:00:00');

        $repository->recordHit(1, $first, $first->modify('-60 seconds'));
        $repository->recordHit(1, $first->modify('+30 seconds'), $first->modify('-30 seconds'));

        $hit = $repository->find(1);
        self::assertNotNull($hit);
        self::assertSame(2, $hit->hits());
        self::assertEquals($first, $hit->lastHitAt());

        $repository->recordHit(1, $first->modify('+60 seconds'), $first);
        $again = $repository->find(1);
        self::assertNotNull($again);
        self::assertSame(3, $again->hits());
        self::assertEquals($first->modify('+60 seconds'), $again->lastHitAt());
        self::assertSame(0, $repository->find(2)?->hits());
    }

    public function testARowThatNoLongerReadsIsLeftOut(): void
    {
        $repository = new DbalRedirectRepository($this->connection);
        $repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')), true, new DateTimeImmutable()));
        $repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/c'), Target::url('/d')), true, new DateTimeImmutable()));
        $repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/e'), Target::url('/f')), true, new DateTimeImmutable()));

        $this->connection->executeStatement("UPDATE phil_redirect SET kind = 'fuzzy' WHERE id = 1");
        $this->connection->executeStatement("UPDATE phil_redirect SET source = 'relative' WHERE id = 2");

        self::assertSame([3], array_map(static fn (Redirect $redirect): ?int => $redirect->id(), $repository->all()));
        self::assertNull($repository->find(1));
        self::assertSame([3], array_map(static fn (Redirect $redirect): ?int => $redirect->id(), $repository->page(0, 10)));
    }

    public function testAStoredRegularExpressionIsReadWhateverItsLength(): void
    {
        $repository = new DbalRedirectRepository($this->connection);
        $pattern = '/'.str_repeat('a', 400);
        $repository->save(new Redirect(new RedirectRule(SourcePattern::regex($pattern, maxLength: 500), Target::url('/b')), true, new DateTimeImmutable()));

        self::assertSame($pattern, $repository->find(1)?->rule()->source->path);
    }

    public function testAPathNotFoundIsAggregated(): void
    {
        $log = new DbalNotFoundLog($this->connection);
        $first = new DateTimeImmutable('2026-05-01 10:00:00');
        $last = new DateTimeImmutable('2026-05-02 10:00:00');

        $log->record('h.example', '/a', 'one.example', $first, 10);
        $log->record('h.example', '/a', null, $last, 10);
        $log->record('h.example', '/b', null, $first, 10);
        $log->record('other.example', '/a', null, $first, 10);

        $entry = $log->find(1);
        self::assertNotNull($entry);
        self::assertSame(1, $entry->id);
        self::assertSame('h.example', $entry->host);
        self::assertSame('/a', $entry->path);
        self::assertSame(2, $entry->hits);
        self::assertEquals($first, $entry->firstSeenAt);
        self::assertEquals($last, $entry->lastSeenAt);
        self::assertSame('one.example', $entry->referrerHost);
        self::assertNull($log->find(2)?->referrerHost);
        self::assertNull($log->find(9));
        self::assertSame(3, $log->count());

        $log->record('h.example', '/a', 'two.example', $last, 10);
        self::assertSame('two.example', $log->find(1)?->referrerHost);
    }

    public function testTheLogIsBoundedForNewPathsOnly(): void
    {
        $log = new DbalNotFoundLog($this->connection);
        $at = new DateTimeImmutable('2026-05-01');

        $log->record('h.example', '/a', null, $at, 2);
        $log->record('h.example', '/b', null, $at, 2);
        $log->record('h.example', '/c', null, $at, 2);
        $log->record('h.example', '/a', null, $at, 2);

        self::assertSame(2, $log->count());
        self::assertSame(2, $log->find(1)?->hits);
    }

    public function testTheLogPagesSearchesRemovesAndPurges(): void
    {
        $log = new DbalNotFoundLog($this->connection);
        $old = new DateTimeImmutable('2026-01-01');
        $recent = new DateTimeImmutable('2026-05-01');

        $log->record('h.example', '/x_y', null, $old, 10);
        $log->record('h.example', '/xay', null, $recent, 10);
        $log->record('h.example', '/xay', null, $recent, 10);
        $log->record('h.example', '/z', null, $recent, 10);

        self::assertSame(['/xay', '/x_y', '/z'], array_map(static fn (NotFoundEntry $entry): string => $entry->path, $log->page(0, 10)));
        self::assertSame(['/x_y'], array_map(static fn (NotFoundEntry $entry): string => $entry->path, $log->page(1, 1)));
        self::assertSame(['/x_y'], array_map(static fn (NotFoundEntry $entry): string => $entry->path, $log->page(0, 10, 'x_')));
        self::assertSame(1, $log->count('x_'));

        self::assertSame(1, $log->purge(new DateTimeImmutable('2026-03-01')));
        self::assertSame(0, $log->purge(new DateTimeImmutable('2026-05-01')));
        self::assertTrue($log->remove(3));
        self::assertFalse($log->remove(3));
        self::assertSame(1, $log->count());
    }

    public function testAnEntryWhoseDatesDoNotReadIsLeftOut(): void
    {
        $log = new DbalNotFoundLog($this->connection);
        $log->record('h.example', '/a', null, new DateTimeImmutable(), 10);
        $log->record('h.example', '/b', null, new DateTimeImmutable(), 10);

        $this->connection->executeStatement("UPDATE phil_redirect_not_found SET referrer_host = '' WHERE id = 2");

        self::assertNull($log->find(2)?->referrerHost);
        self::assertCount(2, $log->page(0, 10));
    }

    public function testTheTablesAreAddedOnce(): void
    {
        $schema = RedirectTables::configure(Schema::editor()->create());

        self::assertTrue($schema->hasTable(RedirectTables::REDIRECTS));
        self::assertTrue($schema->hasTable(RedirectTables::NOT_FOUND));
        self::assertCount(2, RedirectTables::configure($schema)->getTables());
        self::assertTrue($schema->getTable(RedirectTables::REDIRECTS)->getColumn('id')->getAutoincrement());
        self::assertFalse($schema->getTable(RedirectTables::REDIRECTS)->getColumn('host')->getNotnull());
        self::assertTrue($schema->getTable(RedirectTables::NOT_FOUND)->getColumn('host')->getNotnull());
        self::assertSame(hash('sha256', "h.example\n/a"), RedirectTables::hashOf('h.example', '/a'));
    }

    public function testTheColumns(): void
    {
        $schema = RedirectTables::configure(Schema::editor()->create());
        $describe = static function (string $table) use ($schema): array {
            $columns = [];

            foreach (['id', 'host', 'kind', 'source', 'target', 'status', 'query_policy', 'locale', 'enabled', 'hits', 'last_hit_at', 'created_at', 'updated_at', 'path_hash', 'path', 'first_seen_at', 'last_seen_at', 'referrer_host'] as $name) {
                if ($schema->getTable($table)->hasColumn($name)) {
                    $column = $schema->getTable($table)->getColumn($name);
                    $columns[$name] = [$column->getTypeName(), $column->getLength(), $column->getNotnull(), $column->getFixed(), $column->getDefault(), $column->getAutoincrement()];
                }
            }

            return $columns;
        };

        self::assertSame([
            'id' => ['integer', null, true, false, null, true],
            'host' => ['string', 253, false, false, null, false],
            'kind' => ['string', 16, true, false, null, false],
            'source' => ['string', 2048, true, false, null, false],
            'target' => ['string', 2560, false, false, null, false],
            'status' => ['smallint', null, true, false, null, false],
            'query_policy' => ['string', 8, true, false, null, false],
            'locale' => ['string', 35, false, false, null, false],
            'enabled' => ['boolean', null, true, false, null, false],
            'hits' => ['bigint', null, true, false, 0, false],
            'last_hit_at' => ['datetime_immutable', null, false, false, null, false],
            'created_at' => ['datetime_immutable', null, true, false, null, false],
            'updated_at' => ['datetime_immutable', null, true, false, null, false],
        ], $describe(RedirectTables::REDIRECTS));
        self::assertSame([
            'id' => ['integer', null, true, false, null, true],
            'host' => ['string', 253, true, false, null, false],
            'hits' => ['bigint', null, true, false, 0, false],
            'path_hash' => ['string', 64, true, true, null, false],
            'path' => ['string', 2048, true, false, null, false],
            'first_seen_at' => ['datetime_immutable', null, true, false, null, false],
            'last_seen_at' => ['datetime_immutable', null, true, false, null, false],
            'referrer_host' => ['string', 253, false, false, null, false],
        ], $describe(RedirectTables::NOT_FOUND));
    }

    public function testASearchFindsAnExclamationMarkLiterally(): void
    {
        $repository = new DbalRedirectRepository($this->connection);
        $repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a!b'), Target::url('/x')), true, new DateTimeImmutable()));
        $repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/ab'), Target::url('/y')), true, new DateTimeImmutable()));

        $log = new DbalNotFoundLog($this->connection);
        $log->record('h.example', '/a!b', null, new DateTimeImmutable(), 10);
        $log->record('h.example', '/ab', null, new DateTimeImmutable(), 10);

        self::assertSame(1, $repository->count('a!b'));
        self::assertSame(1, $log->count('a!b'));
        self::assertSame('/a!b', $log->page(0, 10, 'a!b')[0]->path);
    }

    public function testTheListenerAddsTheTablesForItsConnectionOnly(): void
    {
        $other = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach ([[$this->connection, true], [$other, false]] as [$connection, $added]) {
            $manager = self::createStub(EntityManagerInterface::class);
            $manager->method('getConnection')->willReturn($connection);
            $event = new GenerateSchemaEventArgs($manager, Schema::editor()->create());

            new RedirectSchemaListener($this->connection)->postGenerateSchema($event);

            self::assertSame($added, $event->getSchema()->hasTable(RedirectTables::REDIRECTS));
        }
    }
}
