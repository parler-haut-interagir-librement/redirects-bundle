<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Http;

use Phil\RedirectsBundle\Domain\Contract\NotFoundLogRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The 404 log in the administration: the paths not found, the most requested first, each with the button that opens
 * the form of a redirect filled in from it, and the one that removes it from the log.
 */
final readonly class NotFoundAdminController
{
    public const string LIST_ROUTE = 'phil_redirects_admin_not_found';

    public function __construct(
        private AdminGuard $guard,
        private NotFoundLogRepository $log,
    ) {
    }

    public function list(Request $request): Response
    {
        $this->guard->denyUnlessGranted();
        $search = $this->guard->search($request);
        $page = $this->guard->page($request);

        return $this->guard->render('not_found', [
            'entries' => $this->log->page(($page - 1) * RedirectAdminController::PAGE_SIZE, RedirectAdminController::PAGE_SIZE, $search),
            'total' => $this->log->count($search),
            'page' => $page,
            'page_size' => RedirectAdminController::PAGE_SIZE,
            'search' => $search,
            'done' => 'deleted' === $request->query->getString('done') ? 'deleted' : '',
        ]);
    }

    public function delete(Request $request, int $id): RedirectResponse
    {
        $this->guard->guardForm($request);

        if (!$this->log->remove($id)) {
            throw new NotFoundHttpException('No such path in the 404 log.');
        }

        return $this->guard->seeOther(self::LIST_ROUTE, ['done' => 'deleted']);
    }
}
