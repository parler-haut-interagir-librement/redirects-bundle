<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Override;
use Phil\RedirectsBundle\Domain\Contract\NotFoundLogRepository;
use Phil\RedirectsBundle\Domain\ValueObject\NotFoundEntry;

/**
 * The log of the paths not found in `phil_redirect_not_found`, through DBAL: a request adds one to the row of its
 * host and path in a single statement, and creates the row when there is none — the race of two first requests ends
 * in the same update.
 */
final readonly class DbalNotFoundLog implements NotFoundLogRepository
{
    private const string COLUMNS = 'id, host, path, hits, first_seen_at, last_seen_at, referrer_host';

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function record(string $host, string $path, ?string $referrerHost, DateTimeImmutable $at, int $capacity): void
    {
        $hash = RedirectTables::hashOf($host, $path);

        if ($this->add($hash, $referrerHost, $at) || $this->count() >= $capacity) {
            return;
        }

        try {
            $this->connection->insert(
                RedirectTables::NOT_FOUND,
                ['path_hash' => $hash, 'host' => $host, 'path' => $path, 'hits' => 1, 'first_seen_at' => $at, 'last_seen_at' => $at, 'referrer_host' => $referrerHost],
                ['hits' => ParameterType::INTEGER, 'first_seen_at' => Types::DATETIME_IMMUTABLE, 'last_seen_at' => Types::DATETIME_IMMUTABLE],
            );
        } catch (UniqueConstraintViolationException) {
            $this->add($hash, $referrerHost, $at);
        }
    }

    #[Override]
    public function find(int $id): ?NotFoundEntry
    {
        $row = $this->connection->fetchAssociative('SELECT '.self::COLUMNS.' FROM '.RedirectTables::NOT_FOUND.' WHERE id = ?', [$id], [ParameterType::INTEGER]);

        return false === $row ? null : $this->hydrate($row);
    }

    #[Override]
    public function page(int $offset, int $limit, string $search = ''): array
    {
        [$where, $parameters] = $this->search($search);
        $sql = $this->connection->getDatabasePlatform()->modifyLimitQuery('SELECT '.self::COLUMNS.' FROM '.RedirectTables::NOT_FOUND.$where.' ORDER BY hits DESC, id', $limit, $offset);

        return array_values(array_filter(array_map($this->hydrate(...), $this->connection->fetchAllAssociative($sql, $parameters))));
    }

    #[Override]
    public function count(string $search = ''): int
    {
        [$where, $parameters] = $this->search($search);

        return (int) new RowReader($this->connection->getDatabasePlatform())->number(['count' => $this->connection->fetchOne('SELECT COUNT(*) FROM '.RedirectTables::NOT_FOUND.$where, $parameters)], 'count');
    }

    #[Override]
    public function remove(int $id): bool
    {
        return 0 < $this->connection->delete(RedirectTables::NOT_FOUND, ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    #[Override]
    public function purge(DateTimeImmutable $notSeenSince): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM '.RedirectTables::NOT_FOUND.' WHERE last_seen_at < ?',
            [$notSeenSince],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    private function add(string $hash, ?string $referrerHost, DateTimeImmutable $at): bool
    {
        return 0 < $this->connection->executeStatement(
            'UPDATE '.RedirectTables::NOT_FOUND.' SET hits = hits + 1, last_seen_at = ?, referrer_host = COALESCE(?, referrer_host) WHERE path_hash = ?',
            [$at, $referrerHost, $hash],
            [Types::DATETIME_IMMUTABLE, ParameterType::STRING, ParameterType::STRING],
        );
    }

    /**
     * @return array{string, list<string>}
     */
    private function search(string $search): array
    {
        return [" WHERE path LIKE ? ESCAPE '!'", ['%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%']];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?NotFoundEntry
    {
        $read = new RowReader($this->connection->getDatabasePlatform());
        $id = $read->number($row, 'id');
        $host = $read->text($row, 'host');
        $path = $read->text($row, 'path');
        $hits = $read->number($row, 'hits');
        $first = $read->moment($row, 'first_seen_at');
        $last = $read->moment($row, 'last_seen_at');

        if (\in_array(null, [$id, $host, $path, $hits, $first, $last], true)) {
            return null;
        }

        $referrer = $read->text($row, 'referrer_host');

        return new NotFoundEntry($id, $host, $path, $hits, $first, $last, '' === $referrer ? null : $referrer);
    }
}
