<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Infrastructure;

use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use Phil\RedirectsBundle\Infrastructure\Http\TransferAdminController;
use Phil\RedirectsBundle\Tests\Unit\Fixture\AdminKit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(TransferAdminController::class)]
final class TransferTest extends TestCase
{
    public function testEveryRedirectIsDownloaded(): void
    {
        $kit = new AdminKit();
        $kit->redirects->create(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')));

        $response = $kit->transferController()->export();

        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="redirects-2026-05-01.csv"', $response->headers->get('Content-Disposition'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));

        $csv = (string) $response->getContent();
        self::assertSame("source,target,status,kind,host,query,locale,enabled,hits,last_hit_at\n/a,/b,301,exact,,drop,,1,0,\n", $csv);
    }

    public function testTheDownloadNeedsTheRole(): void
    {
        $this->expectException(AccessDeniedException::class);
        new AdminKit()->transferController([])->export();
    }

    public function testTheImportForm(): void
    {
        $response = new AdminKit()->transferController()->import(Request::create('/import'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('enctype="multipart/form-data"', (string) $response->getContent());
        self::assertStringNotContainsString('role="status"', (string) $response->getContent());
    }

    public function testAFileIsImportedRegularExpressionsLeftOut(): void
    {
        $kit = new AdminKit();
        $kit->redirects->create(new RedirectRule(SourcePattern::exact('/kept'), Target::url('/x')));

        $request = $kit->post('/import', ['update' => '1']);
        $request->files->set('file', $this->upload("source,target,kind\n/a,/b,exact\n/kept,/y,\n/r+,/c, regex \n"));

        $response = $kit->transferController()->import($request);
        $html = (string) $response->getContent();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('1 created, 1 updated, 0 skipped, 1 refused.', $html);
        self::assertStringContainsString('Line 4: A regular expression is not imported from the administration.', $html);
        self::assertSame('/y', $kit->redirects->get(1)->rule()->target->url);
        self::assertCount(2, $kit->repository->all());
    }

    public function testADryRunSaysWhatWouldBeDone(): void
    {
        $kit = new AdminKit();
        $request = $kit->post('/import', ['dry_run' => '1']);
        $request->files->set('file', $this->upload("source,target\n/a,/b\n"));

        $response = $kit->transferController()->import($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Check only: 1 would be created', (string) $response->getContent());
        self::assertSame([], $kit->repository->all());
    }

    public function testAFileMissingOrTooLargeIsRefused(): void
    {
        $kit = new AdminKit();

        $missing = $kit->transferController()->import($kit->post('/import'));
        self::assertSame(422, $missing->getStatusCode());
        self::assertStringContainsString('Choose a CSV file of 1000 bytes at most.', (string) $missing->getContent());

        $large = $kit->post('/import');
        $large->files->set('file', $this->upload("source,target\n".str_repeat("/a,/b\n", 200)));
        self::assertSame(422, $kit->transferController()->import($large)->getStatusCode());

        $exact = $kit->post('/import');
        $exact->files->set('file', $this->upload(str_pad("source,target\n/a,/b\n", 20, ' ')));
        self::assertSame(200, $kit->transferController(maxBytes: 20)->import($exact)->getStatusCode());

        $failed = $kit->post('/import');
        $failed->files->set('file', new UploadedFile(__FILE__, 'x.csv', 'text/csv', \UPLOAD_ERR_PARTIAL, true));
        self::assertSame(422, $kit->transferController()->import($failed)->getStatusCode());
        self::assertSame([], \array_slice($kit->repository->all(), 1));
    }

    public function testTheImportNeedsTheRoleAndTheToken(): void
    {
        $kit = new AdminKit();

        try {
            $kit->transferController([])->import(Request::create('/import'));
            self::fail('Expected a denial');
        } catch (AccessDeniedException) {
        }

        $this->expectException(BadRequestHttpException::class);
        $kit->transferController()->import($kit->post('/import', [], 'forged'));
    }

    private function upload(string $content): UploadedFile
    {
        return new UploadedFile($this->path($content), 'redirects.csv', 'text/csv', null, true);
    }

    private function path(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phil-redirects-');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return $path;
    }
}
