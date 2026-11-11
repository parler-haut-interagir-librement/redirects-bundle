<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Fixture\Exception;

use Doctrine\DBAL\Exception;
use RuntimeException;

/**
 * A failure of the database, as DBAL reports one.
 */
final class DatabaseGone extends RuntimeException implements Exception
{
}
