<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;

/**
 * The two tables of the bundle, added to the schema Doctrine generates so that the application's migrations create
 * them:
 *
 * - `phil_redirect`: the redirects of the administration, the target written as text ({@see Target::toString()});
 * - `phil_redirect_not_found`: the paths not found, one row per host and path, found again by the SHA-256 of both.
 */
final class RedirectTables
{
    public const string REDIRECTS = 'phil_redirect';

    public const string NOT_FOUND = 'phil_redirect_not_found';

    private function __construct()
    {
    }

    /**
     * The schema with the two tables added, those it already has left as they are.
     */
    public static function configure(Schema $schema): Schema
    {
        $editor = $schema->edit();

        if (!$schema->hasTable(self::REDIRECTS)) {
            $editor->addTable(Table::editor()
                ->setUnquotedName(self::REDIRECTS)
                ->addColumn(self::id())
                ->addColumn(self::string('host', SourcePattern::MAX_HOST_LENGTH, false))
                ->addColumn(self::string('kind', 16))
                ->addColumn(self::string('source', SourcePattern::MAX_PATH_LENGTH))
                ->addColumn(self::string('target', Target::MAX_LENGTH + 512, false))
                ->addColumn(Column::editor()->setUnquotedName('status')->setTypeName(Types::SMALLINT)->setNotNull(true)->create())
                ->addColumn(self::string('query_policy', 8))
                ->addColumn(self::string('locale', 35, false))
                ->addColumn(Column::editor()->setUnquotedName('enabled')->setTypeName(Types::BOOLEAN)->setNotNull(true)->create())
                ->addColumn(self::counter('hits'))
                ->addColumn(self::moment('last_hit_at', false))
                ->addColumn(self::moment('created_at'))
                ->addColumn(self::moment('updated_at'))
                ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
                ->create());
        }

        if (!$schema->hasTable(self::NOT_FOUND)) {
            $editor->addTable(Table::editor()
                ->setUnquotedName(self::NOT_FOUND)
                ->addColumn(self::id())
                ->addColumn(Column::editor()->setUnquotedName('path_hash')->setTypeName(Types::STRING)->setLength(64)->setFixed(true)->setNotNull(true)->create())
                ->addColumn(self::string('host', SourcePattern::MAX_HOST_LENGTH))
                ->addColumn(self::string('path', SourcePattern::MAX_PATH_LENGTH))
                ->addColumn(self::counter('hits'))
                ->addColumn(self::moment('first_seen_at'))
                ->addColumn(self::moment('last_seen_at'))
                ->addColumn(self::string('referrer_host', SourcePattern::MAX_HOST_LENGTH, false))
                ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
                ->addIndex(Index::editor()->setUnquotedName('uniq_phil_redirect_not_found_hash')->setType(IndexType::UNIQUE)->setUnquotedColumnNames('path_hash')->create())
                ->addIndex(Index::editor()->setUnquotedName('idx_phil_redirect_not_found_seen')->setUnquotedColumnNames('last_seen_at')->create())
                ->create());
        }

        return $editor->create();
    }

    /**
     * The key of a path in the log.
     */
    public static function hashOf(string $host, string $path): string
    {
        return hash('sha256', $host."\n".$path);
    }

    private static function id(): Column
    {
        return Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create();
    }

    /**
     * @param non-empty-string $name
     */
    private static function string(string $name, int $length, bool $required = true): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::STRING)->setLength($length)->setNotNull($required)->create();
    }

    /**
     * @param non-empty-string $name
     */
    private static function counter(string $name): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::BIGINT)->setNotNull(true)->setDefaultValue(0)->create();
    }

    /**
     * @param non-empty-string $name
     */
    private static function moment(string $name, bool $required = true): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull($required)->create();
    }
}
