<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * The values of a row as a driver returns them — a number may come as a string of digits, a boolean as `0` or `1` —,
 * each read strictly: a value that does not read gives `null`, and the row is then left out by its repository.
 */
final readonly class RowReader
{
    public function __construct(
        private AbstractPlatform $platform,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function text(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function number(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return \is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function moment(array $row, string $column): ?DateTimeImmutable
    {
        try {
            $value = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($row[$column] ?? null, $this->platform);
        } catch (ConversionException) {
            return null;
        }

        return $value instanceof DateTimeImmutable ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function flag(array $row, string $column): bool
    {
        return true === Type::getType(Types::BOOLEAN)->convertToPHPValue($row[$column] ?? null, $this->platform);
    }
}
