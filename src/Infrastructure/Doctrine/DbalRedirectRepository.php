<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Override;
use Phil\RedirectsBundle\Domain\Contract\RedirectRepository;
use Phil\RedirectsBundle\Domain\Enum\MatchKind;
use Phil\RedirectsBundle\Domain\Enum\QueryPolicy;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\Model\Redirect;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use ValueError;

/**
 * The redirects in `phil_redirect`, through DBAL. A row that no longer reads as a redirect — changed by hand, or
 * written by a later version — is left out rather than breaking the table: every request not found depends on it.
 */
final readonly class DbalRedirectRepository implements RedirectRepository
{
    private const string COLUMNS = 'id, host, kind, source, target, status, query_policy, locale, enabled, hits, last_hit_at, created_at, updated_at';

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function find(int $id): ?Redirect
    {
        $row = $this->connection->fetchAssociative('SELECT '.self::COLUMNS.' FROM '.RedirectTables::REDIRECTS.' WHERE id = ?', [$id], [ParameterType::INTEGER]);

        return false === $row ? null : $this->hydrate($row);
    }

    #[Override]
    public function all(): array
    {
        return $this->hydrateAll($this->connection->fetchAllAssociative('SELECT '.self::COLUMNS.' FROM '.RedirectTables::REDIRECTS.' ORDER BY id'));
    }

    #[Override]
    public function page(int $offset, int $limit, string $search = ''): array
    {
        [$where, $parameters] = $this->search($search);
        $sql = $this->connection->getDatabasePlatform()->modifyLimitQuery('SELECT '.self::COLUMNS.' FROM '.RedirectTables::REDIRECTS.$where.' ORDER BY id DESC', $limit, $offset);

        return $this->hydrateAll($this->connection->fetchAllAssociative($sql, $parameters));
    }

    #[Override]
    public function count(string $search = ''): int
    {
        [$where, $parameters] = $this->search($search);

        return (int) new RowReader($this->connection->getDatabasePlatform())->number(['count' => $this->connection->fetchOne('SELECT COUNT(*) FROM '.RedirectTables::REDIRECTS.$where, $parameters)], 'count');
    }

    #[Override]
    public function save(Redirect $redirect): void
    {
        $rule = $redirect->rule();
        $values = [
            'host' => $rule->source->host,
            'kind' => $rule->source->kind->value,
            'source' => $rule->source->path,
            'target' => $rule->target->isNone() ? null : $rule->target->toString(),
            'status' => $rule->status->value,
            'query_policy' => $rule->query->value,
            'locale' => $rule->locale,
            'enabled' => $redirect->isEnabled(),
            'updated_at' => $redirect->updatedAt(),
        ];
        $types = ['status' => ParameterType::INTEGER, 'enabled' => ParameterType::BOOLEAN, 'updated_at' => Types::DATETIME_IMMUTABLE];
        $id = $redirect->id();

        if (null !== $id) {
            $this->connection->update(RedirectTables::REDIRECTS, $values, ['id' => $id], $types + ['id' => ParameterType::INTEGER]);

            return;
        }

        $this->connection->insert(
            RedirectTables::REDIRECTS,
            $values + ['hits' => $redirect->hits(), 'last_hit_at' => $redirect->lastHitAt(), 'created_at' => $redirect->createdAt()],
            $types + ['hits' => ParameterType::INTEGER, 'last_hit_at' => Types::DATETIME_IMMUTABLE, 'created_at' => Types::DATETIME_IMMUTABLE],
        );
        $redirect->identify((int) $this->connection->lastInsertId());
    }

    #[Override]
    public function remove(int $id): bool
    {
        return 0 < $this->connection->delete(RedirectTables::REDIRECTS, ['id' => $id], ['id' => ParameterType::INTEGER]);
    }

    #[Override]
    public function recordHit(int $id, DateTimeImmutable $at, DateTimeImmutable $throttle): void
    {
        $this->connection->executeStatement(
            'UPDATE '.RedirectTables::REDIRECTS.' SET hits = hits + 1, last_hit_at = CASE WHEN last_hit_at IS NULL OR last_hit_at <= ? THEN ? ELSE last_hit_at END WHERE id = ?',
            [$throttle, $at, $id],
            [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, ParameterType::INTEGER],
        );
    }

    /**
     * @return array{string, list<string>}
     */
    private function search(string $search): array
    {
        $like = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        return [" WHERE source LIKE ? ESCAPE '!' OR target LIKE ? ESCAPE '!'", [$like, $like]];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Redirect>
     */
    private function hydrateAll(array $rows): array
    {
        return array_values(array_filter(array_map($this->hydrate(...), $rows)));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?Redirect
    {
        $read = new RowReader($this->connection->getDatabasePlatform());
        $id = $read->number($row, 'id');
        $hits = $read->number($row, 'hits');
        $created = $read->moment($row, 'created_at');
        $updated = $read->moment($row, 'updated_at');

        if (\in_array(null, [$id, $hits, $created, $updated], true)) {
            return null;
        }

        try {
            $rule = new RedirectRule(
                SourcePattern::of(MatchKind::from((string) $read->text($row, 'kind')), (string) $read->text($row, 'source'), $read->text($row, 'host'), \PHP_INT_MAX),
                Target::fromString((string) $read->text($row, 'target')),
                RedirectStatus::from((int) $read->number($row, 'status')),
                QueryPolicy::from((string) $read->text($row, 'query_policy')),
                $read->text($row, 'locale'),
            );
        } catch (InvalidRedirect|ValueError) {
            return null;
        }

        return new Redirect($rule, $read->flag($row, 'enabled'), $created, $id, $hits, $read->moment($row, 'last_hit_at'), $updated);
    }
}
