<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Override;
use Phil\RedirectsBundle\Infrastructure\Doctrine\RedirectTables;
use Phil\RedirectsBundle\Infrastructure\Http\AdminGuard;
use Phil\RedirectsBundle\Tests\Functional\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The bundle in a kernel of its own (`App\TestKernel`), with its two tables rebuilt for each test. Each request goes
 * through the kernel as a worker would serve it, terminated, then the services reset.
 */
abstract class FunctionalTestCase extends KernelTestCase
{
    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $connection = $this->connection();
        $schemaManager = $connection->createSchemaManager();

        foreach ([RedirectTables::REDIRECTS, RedirectTables::NOT_FOUND] as $table) {
            if ($schemaManager->tablesExist([$table])) {
                $schemaManager->dropTable($table);
            }
        }

        foreach (RedirectTables::configure(Schema::editor()->create())->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
    }

    protected function connection(): Connection
    {
        return $this->service('doctrine.dbal.default_connection', Connection::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $id, string $class): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $form
     * @param array<string, mixed>  $files
     */
    protected function request(string $method, string $uri, array $headers = [], array $form = [], array $files = []): Response
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);
        $request = Request::create($uri, $method, $form, [], $files);
        $request->headers->add($headers + ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0']);

        $response = $kernel->handle($request);

        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }

        $this->service('services_resetter', ResetInterface::class)->reset();

        return $response;
    }

    /**
     * A form of the administration, posted by an editor with a valid token.
     *
     * @param array<string, string> $form
     * @param array<string, mixed>  $files
     */
    protected function post(string $uri, array $form = [], string $roles = 'ROLE_EDITOR', array $files = []): Response
    {
        $token = $this->service('security.csrf.token_manager', CsrfTokenManagerInterface::class)->getToken(AdminGuard::CSRF_TOKEN_ID)->getValue();

        return $this->request('POST', $uri, ['X-Roles' => $roles], $form + ['_token' => $token], $files);
    }
}
