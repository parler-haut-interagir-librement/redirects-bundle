<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application\Csv;

use Generator;
use Phil\RedirectsBundle\Application\RedirectDraft;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\Model\Redirect;
use Phil\RedirectsBundle\Domain\ValueObject\Target;

/**
 * The CSV form of the redirects, one per line after a header that names the columns:
 *
 *     source,target,status,kind,host,query,locale,enabled,hits,last_hit_at
 *
 * Only `source` is required; a missing column takes its default (`301`, `exact`, every host, `drop`, every locale,
 * enabled). The counters are written for the reader and ignored on import. A cell that a spreadsheet would read as a
 * formula is written after a `'`, which the import removes again. A file is read up to a number of lines and a length
 * of cell, so that an upload cannot hold the administration.
 */
final readonly class RedirectCsv
{
    public const array COLUMNS = ['source', 'target', 'status', 'kind', 'host', 'query', 'locale', 'enabled', 'hits', 'last_hit_at'];

    public const int DEFAULT_MAX_LINES = 10000;

    private const int MAX_CELL = Target::MAX_LENGTH + 16;

    public function __construct(
        private int $maxLines = self::DEFAULT_MAX_LINES,
    ) {
    }

    /**
     * The lines of a file, by line number: a draft, or the reason the line cannot be one.
     *
     * @return Generator<int, RedirectDraft|InvalidRedirect>
     */
    public function read(string $csv): Generator
    {
        $stream = fopen('php://temp', 'r+');

        if (false === $stream) {
            return;
        }

        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, null, ',', '"', '');

        if (!\is_array($header) || !\in_array('source', $header = array_map(static fn (?string $name): string => strtolower(trim((string) $name, " \t\u{FEFF}")), $header), true)) {
            fclose($stream);

            yield 1 => InvalidRedirect::because('csv_header', 'The first line names the columns, among them "source".');

            return;
        }

        $line = 1;

        while (false !== ($cells = fgetcsv($stream, null, ',', '"', ''))) {
            ++$line;

            if ([null] === $cells) {
                continue;
            }

            if ($line - 1 > $this->maxLines) {
                yield $line => InvalidRedirect::because('csv_lines', 'A file has at most %max% redirects.', ['%max%' => (string) $this->maxLines]);

                break;
            }

            yield $line => $this->draft($header, $cells);
        }

        fclose($stream);
    }

    /**
     * Every redirect as a file.
     *
     * @param iterable<Redirect> $redirects
     */
    public function write(iterable $redirects): string
    {
        $stream = fopen('php://temp', 'r+');

        if (false === $stream) {
            return '';
        }

        fputcsv($stream, self::COLUMNS, ',', '"', '');

        foreach ($redirects as $redirect) {
            $rule = $redirect->rule();
            fputcsv($stream, [
                $this->escaped($rule->source->path),
                $this->escaped($rule->target->toString()),
                $rule->status->value,
                $rule->source->kind->value,
                $rule->source->host ?? '',
                $rule->query->value,
                $rule->locale ?? '',
                $redirect->isEnabled() ? 1 : 0,
                $redirect->hits(),
                $redirect->lastHitAt()?->format(\DATE_ATOM) ?? '',
            ], ',', '"', '');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * @param list<string>      $header
     * @param list<string|null> $cells
     */
    private function draft(array $header, array $cells): RedirectDraft|InvalidRedirect
    {
        $values = [];

        foreach ($header as $index => $name) {
            $cell = $cells[$index] ?? '';

            if (\strlen($cell) > self::MAX_CELL) {
                return InvalidRedirect::because('csv_cell', 'A cell has at most %max% characters.', ['%max%' => (string) self::MAX_CELL]);
            }

            $values[$name] = $this->unescaped($cell);
        }

        $enabled = strtolower(trim($values['enabled'] ?? '1'));

        return new RedirectDraft(
            $values['source'] ?? '',
            $values['target'] ?? '',
            '' === trim($values['status'] ?? '') ? '301' : $values['status'],
            '' === trim($values['kind'] ?? '') ? 'exact' : $values['kind'],
            $values['host'] ?? '',
            '' === trim($values['query'] ?? '') ? 'drop' : $values['query'],
            $values['locale'] ?? '',
            !\in_array($enabled, ['0', 'false', 'no', 'off'], true),
        );
    }

    private function escaped(string $cell): string
    {
        return '' !== $cell && str_contains('=+-@', $cell[0]) ? "'".$cell : $cell;
    }

    private function unescaped(string $cell): string
    {
        return \strlen($cell) > 1 && "'" === $cell[0] && str_contains('=+-@', $cell[1]) ? substr($cell, 1) : $cell;
    }
}
