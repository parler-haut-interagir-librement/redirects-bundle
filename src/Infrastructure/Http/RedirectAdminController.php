<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Http;

use Phil\RedirectsBundle\Application\Exception\RedirectNotFound;
use Phil\RedirectsBundle\Application\Exception\RedirectRefused;
use Phil\RedirectsBundle\Application\RedirectDraft;
use Phil\RedirectsBundle\Application\Redirects;
use Phil\RedirectsBundle\Domain\Contract\NotFoundLogRepository;
use Phil\RedirectsBundle\Domain\Contract\RedirectRepository;
use Phil\RedirectsBundle\Domain\Enum\MatchKind;
use Phil\RedirectsBundle\Domain\Enum\QueryPolicy;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Phil\RedirectsBundle\Domain\Model\Redirect;
use Phil\RedirectsBundle\Domain\ValueObject\NotFoundEntry;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The redirects in the administration: the list with its search, the form that creates or changes one — opened from a
 * path of the 404 log, it comes filled in, and the entry leaves the log once the redirect is saved —, and the buttons
 * that enable, disable or remove one. Every change goes through `Redirects`, whose refusals the form shows; a regular
 * expression needs the role of regular expressions on top.
 */
final readonly class RedirectAdminController
{
    public const string LIST_ROUTE = 'phil_redirects_admin_list';

    public const string CREATE_ROUTE = 'phil_redirects_admin_create';

    public const string EDIT_ROUTE = 'phil_redirects_admin_edit';

    public const int PAGE_SIZE = 50;

    /**
     * The outcomes the list reports after a change: one of these, never what a crafted link puts in the query string.
     */
    private const array OUTCOMES = ['created', 'updated', 'deleted', 'enabled', 'disabled'];

    public function __construct(
        private AdminGuard $guard,
        private Redirects $redirects,
        private RedirectRepository $repository,
        private NotFoundLogRepository $notFound,
    ) {
    }

    public function list(Request $request): Response
    {
        $this->guard->denyUnlessGranted();
        $search = $this->guard->search($request);
        $page = $this->guard->page($request);
        $done = $request->query->getString('done');

        return $this->guard->render('list', [
            'redirects' => $this->repository->page(($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, $search),
            'total' => $this->repository->count($search),
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'search' => $search,
            'done' => \in_array($done, self::OUTCOMES, true) ? $done : '',
        ]);
    }

    public function create(Request $request): Response
    {
        $this->guard->denyUnlessGranted();
        $from = $this->fromLog($request);

        if (!$request->isMethod('POST')) {
            return $this->form(new RedirectDraft($from->path ?? '', host: $from->host ?? ''), null, $from);
        }

        $this->guard->guardForm($request);
        $draft = $this->submitted($request);

        try {
            $this->assertRegexAllowed($draft);
            $this->redirects->create($draft->rule($this->redirects->regexMaxLength()), $draft->enabled);
        } catch (InvalidRedirect|RedirectRefused $refused) {
            return $this->form($draft, null, $from, $refused);
        }

        if ($from instanceof NotFoundEntry) {
            $this->notFound->remove($from->id);
        }

        return $this->guard->seeOther(self::LIST_ROUTE, ['done' => 'created']);
    }

    public function edit(Request $request, int $id): Response
    {
        $this->guard->denyUnlessGranted();
        $redirect = $this->redirect($id);

        if (!$request->isMethod('POST')) {
            return $this->form(RedirectDraft::fromRule($redirect->rule(), $redirect->isEnabled()), $id);
        }

        $this->guard->guardForm($request);
        $draft = $this->submitted($request);

        try {
            $this->assertRegexAllowed($draft, MatchKind::Regex === $redirect->rule()->source->kind);
            $this->redirects->update($id, $draft->rule($this->redirects->regexMaxLength()), $draft->enabled);
        } catch (InvalidRedirect|RedirectRefused $refused) {
            return $this->form($draft, $id, null, $refused);
        }

        return $this->guard->seeOther(self::LIST_ROUTE, ['done' => 'updated']);
    }

    public function toggle(Request $request, int $id): Response
    {
        $this->guard->guardForm($request);
        $enabled = '1' === $request->request->getString('enabled');

        try {
            $this->redirects->enable($id, $enabled);
        } catch (RedirectNotFound $redirectNotFound) {
            throw new NotFoundHttpException($redirectNotFound->getMessage(), $redirectNotFound);
        } catch (RedirectRefused $refused) {
            return $this->form(RedirectDraft::fromRule($this->redirect($id)->rule(), $enabled), $id, null, $refused);
        }

        return $this->guard->seeOther(self::LIST_ROUTE, ['done' => $enabled ? 'enabled' : 'disabled']);
    }

    public function delete(Request $request, int $id): RedirectResponse
    {
        $this->guard->guardForm($request);

        try {
            $this->redirects->delete($id);
        } catch (RedirectNotFound $redirectNotFound) {
            throw new NotFoundHttpException($redirectNotFound->getMessage(), $redirectNotFound);
        }

        return $this->guard->seeOther(self::LIST_ROUTE, ['done' => 'deleted']);
    }

    private function form(RedirectDraft $draft, ?int $id, ?NotFoundEntry $from = null, InvalidRedirect|RedirectRefused|null $refused = null): Response
    {
        return $this->guard->render('form', [
            'draft' => $draft,
            'id' => $id,
            'from' => $from,
            'kinds' => array_map(static fn (MatchKind $kind): string => $kind->value, array_filter(
                MatchKind::cases(),
                fn (MatchKind $kind): bool => MatchKind::Regex !== $kind || ($this->redirects->regexAllowed() && $this->guard->mayWriteRegex()),
            )),
            'statuses' => array_map(static fn (RedirectStatus $status): int => $status->value, RedirectStatus::cases()),
            'queries' => array_map(static fn (QueryPolicy $query): string => $query->value, QueryPolicy::cases()),
            'error' => null === $refused ? null : ['reason' => $refused->reason, 'parameters' => $refused->parameters],
        ], null === $refused ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @throws RedirectRefused
     */
    private function assertRegexAllowed(RedirectDraft $draft, bool $wasRegex = false): void
    {
        if (($wasRegex || MatchKind::Regex->value === trim($draft->kind)) && !$this->guard->mayWriteRegex()) {
            throw RedirectRefused::because('regex_role', 'Writing a regular expression needs a role you do not have.');
        }
    }

    private function redirect(int $id): Redirect
    {
        try {
            return $this->redirects->get($id);
        } catch (RedirectNotFound $redirectNotFound) {
            throw new NotFoundHttpException($redirectNotFound->getMessage(), $redirectNotFound);
        }
    }

    private function fromLog(Request $request): ?NotFoundEntry
    {
        return $request->query->has('from') ? $this->notFound->find($request->query->getInt('from')) : null;
    }

    private function submitted(Request $request): RedirectDraft
    {
        $form = $request->request;

        return new RedirectDraft(
            $form->getString('source'),
            $form->getString('target'),
            $form->getString('status', '301'),
            $form->getString('kind', 'exact'),
            $form->getString('host'),
            $form->getString('query', 'drop'),
            $form->getString('locale'),
            '1' === $form->getString('enabled'),
        );
    }
}
