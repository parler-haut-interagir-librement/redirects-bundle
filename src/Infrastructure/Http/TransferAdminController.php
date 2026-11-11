<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Http;

use Phil\RedirectsBundle\Application\Csv\RedirectCsv;
use Phil\RedirectsBundle\Application\RedirectDraft;
use Phil\RedirectsBundle\Application\Redirects;
use Phil\RedirectsBundle\Domain\Contract\RedirectRepository;
use Phil\RedirectsBundle\Domain\Exception\InvalidRedirect;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CSV of the redirects in the administration: every redirect downloaded, and a file uploaded and imported line by
 * line through `Redirects::import()` — tried first without writing anything when asked, and refused above a size. An
 * import by the administration never writes a regular expression: those are written one at a time, by a user with
 * their role.
 */
final readonly class TransferAdminController
{
    public const string IMPORT_ROUTE = 'phil_redirects_admin_import';

    public const int DEFAULT_MAX_BYTES = 2_000_000;

    public function __construct(
        private AdminGuard $guard,
        private Redirects $redirects,
        private RedirectRepository $repository,
        private RedirectCsv $csv,
        private ClockInterface $clock,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
    }

    public function export(): Response
    {
        $this->guard->denyUnlessGranted();
        $response = new Response($this->csv->write($this->repository->all()));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="redirects-'.$this->clock->now()->format('Y-m-d').'.csv"');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function import(Request $request): Response
    {
        $this->guard->denyUnlessGranted();

        if (!$request->isMethod('POST')) {
            return $this->guard->render('import', ['report' => null, 'error' => null]);
        }

        $this->guard->guardForm($request);
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > $this->maxBytes) {
            return $this->guard->render('import', ['report' => null, 'error' => ['reason' => 'csv_file', 'parameters' => ['%max%' => $this->maxBytes]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $report = $this->redirects->import(
            $this->withoutRegex($this->csv->read((string) file_get_contents($file->getPathname()))),
            '1' === $request->request->getString('update'),
            '1' === $request->request->getString('dry_run'),
        );

        return $this->guard->render('import', ['report' => $report, 'error' => null], $report->isClean() ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param iterable<int, RedirectDraft|InvalidRedirect> $lines
     *
     * @return iterable<int, RedirectDraft|InvalidRedirect>
     */
    private function withoutRegex(iterable $lines): iterable
    {
        foreach ($lines as $line => $draft) {
            yield $line => $draft instanceof RedirectDraft && 'regex' === trim($draft->kind)
                ? InvalidRedirect::because('regex_import', 'A regular expression is not imported from the administration.')
                : $draft;
        }
    }
}
