<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Application;

use Phil\RedirectsBundle\Application\Exception\RedirectNotFound;
use Phil\RedirectsBundle\Application\Exception\RedirectRefused;
use Phil\RedirectsBundle\Domain\Contract\RedirectRepository;
use Phil\RedirectsBundle\Domain\Enum\MatchKind;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\Model\Redirect;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Psr\Clock\ClockInterface;

/**
 * The redirects of the administration: created, changed, enabled, disabled, removed, imported and checked.
 *
 * Every save goes through the same gate: a regular expression only while the application allows them, an external
 * target only to an allowed host ({@see TargetPolicy}), one redirect per source and locale, and — for an enabled
 * redirect — no loop and no chain with those in force ({@see ChainInspector}). Then the table is invalidated.
 */
final readonly class Redirects
{
    public function __construct(
        private RedirectRepository $repository,
        private RedirectTableCache $tables,
        private ChainInspector $inspector,
        private TargetPolicy $targets,
        private ClockInterface $clock,
        private bool $regex = false,
        private int $regexMaxLength = SourcePattern::DEFAULT_REGEX_MAX_LENGTH,
    ) {
    }

    public function regexAllowed(): bool
    {
        return $this->regex;
    }

    public function regexMaxLength(): int
    {
        return $this->regexMaxLength;
    }

    /**
     * @throws RedirectNotFound
     */
    public function get(int $id): Redirect
    {
        return $this->repository->find($id) ?? throw RedirectNotFound::withId($id);
    }

    /**
     * @throws RedirectRefused
     */
    public function create(RedirectRule $rule, bool $enabled = true): Redirect
    {
        $this->admit($rule, $enabled, null, $this->stored(), $this->tables->fresh());
        $redirect = new Redirect($rule, $enabled, $this->clock->now());
        $this->repository->save($redirect);
        $this->tables->invalidate();

        return $redirect;
    }

    /**
     * @throws RedirectNotFound
     * @throws RedirectRefused
     */
    public function update(int $id, RedirectRule $rule, bool $enabled): Redirect
    {
        $redirect = $this->get($id);
        $this->admit($rule, $enabled, $redirect, $this->stored(), $this->tables->fresh());
        $redirect->change($rule, $enabled, $this->clock->now());
        $this->repository->save($redirect);
        $this->tables->invalidate();

        return $redirect;
    }

    /**
     * @throws RedirectNotFound
     * @throws RedirectRefused
     */
    public function enable(int $id, bool $enabled): Redirect
    {
        return $this->update($id, $this->get($id)->rule(), $enabled);
    }

    /**
     * @throws RedirectNotFound
     */
    public function delete(int $id): void
    {
        if (!$this->repository->remove($id)) {
            throw RedirectNotFound::withId($id);
        }

        $this->tables->invalidate();
    }

    /**
     * Imports the lines of a file in their order, each through the gate of a save, the earlier lines counting for
     * the later ones. A source already there is skipped, or updated with `$update`; with `$dryRun`, nothing is
     * written and the report says what would have been.
     *
     * @param iterable<int, RedirectDraft|InvalidRedirect> $lines
     */
    public function import(iterable $lines, bool $update = false, bool $dryRun = false): ImportReport
    {
        $report = new ImportReport($dryRun);
        $stored = $this->stored();
        $table = $this->tables->fresh();

        foreach ($lines as $line => $draft) {
            try {
                if ($draft instanceof InvalidRedirect) {
                    throw $draft;
                }

                $rule = $draft->rule($this->regexMaxLength);
                $known = $stored[$this->keyOf($rule)] ?? null;

                if ($known instanceof Redirect && !$update) {
                    $report->skipped[] = $line;

                    continue;
                }

                $table = $this->admit($rule, $draft->enabled, $known, $stored, $table);
            } catch (InvalidRedirect|RedirectRefused $refused) {
                $report->rejected[$line] = $refused->getMessage();

                continue;
            }

            if ($dryRun) {
                // What the later lines are checked against, without touching what is stored.
                $redirect = new Redirect($rule, $draft->enabled, $this->clock->now(), $known?->id());
            } else {
                $redirect = $known ?? new Redirect($rule, $draft->enabled, $this->clock->now());
                $redirect->change($rule, $draft->enabled, $this->clock->now());
                $this->repository->save($redirect);
            }

            $stored[$this->keyOf($rule)] = $redirect;
            if ($known instanceof Redirect) {
                ++$report->updated;
            } else {
                ++$report->created;
            }
        }

        if (!$dryRun && $report->created + $report->updated > 0) {
            $this->tables->invalidate();
        }

        return $report;
    }

    /**
     * The stored redirects that the gate would refuse today: a target no longer allowed, a regular expression while
     * they are off, a loop or a chain with the redirects in force.
     *
     * @return list<Finding>
     */
    public function check(): array
    {
        $findings = [];
        $table = $this->tables->fresh();

        foreach ($this->repository->all() as $redirect) {
            $rule = $redirect->rule();

            try {
                $this->allowed($rule);

                if ($redirect->isEnabled()) {
                    $this->inspector->inspect($rule, $table);
                }
            } catch (RedirectRefused $refused) {
                $findings[] = new Finding((int) $redirect->id(), $rule->source->path, $refused->reason, $refused->getMessage());
            }
        }

        return $findings;
    }

    /**
     * @param array<string, Redirect> $stored the stored redirects by source and locale
     *
     * @return RedirectTable the table with the redirect in force
     *
     * @throws RedirectRefused
     */
    private function admit(RedirectRule $rule, bool $enabled, ?Redirect $replaced, array $stored, RedirectTable $table): RedirectTable
    {
        $this->allowed($rule);
        $taken = $stored[$this->keyOf($rule)] ?? null;

        if ($taken instanceof Redirect && $taken !== $replaced) {
            throw RedirectRefused::because('duplicate', 'A redirect from "%source%" already exists.', ['%source%' => $rule->source->path]);
        }

        if ($replaced instanceof Redirect) {
            $table = $table->without($replaced->rule());
        }

        if (!$enabled) {
            return $table;
        }

        $table = $table->without($rule)->with($rule);
        $this->inspector->inspect($rule, $table);

        return $table;
    }

    /**
     * @throws RedirectRefused
     */
    private function allowed(RedirectRule $rule): void
    {
        if (!$this->regex && MatchKind::Regex === $rule->source->kind) {
            throw RedirectRefused::because('regex_disabled', 'Regular expressions are not allowed.');
        }

        if (!$this->targets->allows($rule->target)) {
            throw RedirectRefused::because('external_target', 'The host "%host%" is not one a redirect may send to.', ['%host%' => $rule->target->host ?? '']);
        }
    }

    /**
     * @return array<string, Redirect>
     */
    private function stored(): array
    {
        $stored = [];

        foreach ($this->repository->all() as $redirect) {
            $stored[$this->keyOf($redirect->rule())] = $redirect;
        }

        return $stored;
    }

    private function keyOf(RedirectRule $rule): string
    {
        return implode('
', [$rule->source->kind->value, $rule->source->host ?? '', $rule->source->path, $rule->locale ?? '']);
    }
}
