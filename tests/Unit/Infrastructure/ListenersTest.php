<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use LogicException;
use Phil\RedirectsBundle\Application\Contract\NotFoundFilter;
use Phil\RedirectsBundle\Application\LocationBuilder;
use Phil\RedirectsBundle\Application\NotFoundHit;
use Phil\RedirectsBundle\Application\NotFoundRecorder;
use Phil\RedirectsBundle\Application\OldDomain;
use Phil\RedirectsBundle\Application\OldDomains;
use Phil\RedirectsBundle\Application\RedirectResolver;
use Phil\RedirectsBundle\Application\RedirectTableCache;
use Phil\RedirectsBundle\Application\StoredRedirects;
use Phil\RedirectsBundle\Domain\Enum\QueryPolicy;
use Phil\RedirectsBundle\Domain\Enum\RedirectStatus;
use Phil\RedirectsBundle\Domain\Model\Redirect;
use Phil\RedirectsBundle\Domain\ValueObject\RedirectRule;
use Phil\RedirectsBundle\Domain\ValueObject\SourcePattern;
use Phil\RedirectsBundle\Domain\ValueObject\Target;
use Phil\RedirectsBundle\Infrastructure\Http\NotFoundListener;
use Phil\RedirectsBundle\Infrastructure\Http\OldDomainListener;
use Phil\RedirectsBundle\Tests\Unit\Fixture\InMemoryNotFoundLog;
use Phil\RedirectsBundle\Tests\Unit\Fixture\InMemoryRedirects;
use Phil\RedirectsBundle\Tests\Unit\Fixture\ListProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

#[CoversClass(NotFoundListener::class)]
#[CoversClass(OldDomainListener::class)]
final class ListenersTest extends TestCase
{
    private InMemoryRedirects $repository;

    private InMemoryNotFoundLog $log;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->repository = new InMemoryRedirects();
        $this->log = new InMemoryNotFoundLog();
        $this->clock = new MockClock('2026-05-01 10:00:00');
    }

    public function testANotFoundRequestIsRedirectedAndItsHitWrittenAfterTheResponse(): void
    {
        $this->repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'), RedirectStatus::Found), true, new DateTimeImmutable()));
        $listener = $this->listener();
        $event = $this->event(Request::create('https://h.example/a'));

        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/b', $response->getTargetUrl());
        self::assertSame(302, $response->getStatusCode());
        self::assertTrue($event->isAllowingCustomResponseCode());
        self::assertSame([], $this->repository->hitsRecorded());

        $listener->onKernelTerminate();
        self::assertEquals([[1, $this->clock->now(), $this->clock->now()->modify('-30 seconds')]], $this->repository->hitsRecorded());
        self::assertSame([], $this->log->recordedSoFar());

        $listener->onKernelTerminate();
        self::assertCount(1, $this->repository->hitsRecorded());
    }

    public function testTheLocaleAndQueryOfTheRequestAreUsed(): void
    {
        $this->repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a', 'h.example'), Target::url('/b'), query: QueryPolicy::Keep, locale: 'fr'), true, new DateTimeImmutable()));
        $request = Request::create('https://h.example/a?x=1');
        $request->setLocale('fr');

        $event = $this->event($request);

        $this->listener()->onKernelException($event);

        self::assertSame('/b?x=1', $event->getResponse()?->headers->get('Location'));

        $english = $this->event(Request::create('https://h.example/a?x=1'));
        $this->listener()->onKernelException($english);
        self::assertNull($english->getResponse());
    }

    public function testAGoneBecomesTheApplicationsErrorPage(): void
    {
        $this->repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::none(), RedirectStatus::Gone), true, new DateTimeImmutable()));
        $listener = $this->listener();
        $event = $this->event(Request::create('/a'));
        $original = $event->getThrowable();

        $listener->onKernelException($event);

        self::assertNull($event->getResponse());
        self::assertInstanceOf(GoneHttpException::class, $event->getThrowable());
        self::assertSame($original, $event->getThrowable()->getPrevious());
        self::assertSame('The page is gone.', $event->getThrowable()->getMessage());
        $listener->onKernelTerminate();
        self::assertCount(1, $this->repository->hitsRecorded());
    }

    public function testAProvidedRedirectHasNoHitToWrite(): void
    {
        $listener = $this->listener([new RedirectRule(SourcePattern::exact('/a'), Target::url('/b'))]);
        $listener->onKernelException($this->event(Request::create('/a')));
        $listener->onKernelTerminate();

        self::assertSame([], $this->repository->hitsRecorded());
        self::assertSame([], $this->log->recordedSoFar());
    }

    public function testTheHitsAreNotWrittenWhenOff(): void
    {
        $this->repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')), true, new DateTimeImmutable()));
        $listener = new NotFoundListener($this->resolver(), $this->clock);

        $listener->onKernelException($event = $this->event(Request::create('/a')));
        $listener->onKernelTerminate();

        self::assertNotNull($event->getResponse());
        self::assertSame([], $this->repository->hitsRecorded());
    }

    public function testARequestThatNothingMatchesIsLoggedAfterTheResponse(): void
    {
        $listener = $this->listener();
        $request = Request::create('https://h.example/missing', server: ['HTTP_REFERER' => 'https://from.example/x', 'HTTP_USER_AGENT' => 'Firefox', 'HTTP_X_VERDICT' => 'human']);
        $event = $this->event($request);

        $listener->onKernelException($event);
        self::assertNull($event->getResponse());
        self::assertInstanceOf(NotFoundHttpException::class, $event->getThrowable());
        self::assertSame([], $this->log->recordedSoFar());

        $listener->onKernelTerminate();
        self::assertEquals([['h.example', '/missing', 'from.example', $this->clock->now(), NotFoundRecorder::DEFAULT_CAPACITY]], $this->log->recordedSoFar());

        $listener->onKernelTerminate();
        self::assertCount(1, $this->log->recordedSoFar());
    }

    public function testTheVerdictHeaderReachesTheFilters(): void
    {
        $seen = [];
        $filter = new class($seen) implements NotFoundFilter {
            /** @param list<?string> $seen */
            public function __construct(public array &$seen)
            {
            }

            public function ignores(NotFoundHit $hit): bool
            {
                $this->seen[] = $hit->verdict.'|'.$hit->userAgent.'|'.$hit->referrer;

                return false;
            }
        };
        $recorder = new NotFoundRecorder($this->log, $this->clock, [$filter]);
        $request = Request::create('/missing', server: ['HTTP_USER_AGENT' => 'Firefox', 'HTTP_REFERER' => 'https://r.example/', 'HTTP_X_VERDICT' => 'robot']);

        foreach ([['X-Verdict', 'robot|Firefox|https://r.example/'], [null, '|Firefox|https://r.example/']] as [$header, $expected]) {
            $listener = new NotFoundListener($this->resolver(), $this->clock, null, $recorder, 30, $header);
            $listener->onKernelException($this->event($request));
            $listener->onKernelTerminate();
            self::assertSame($expected, end($filter->seen));
        }
    }

    public function testOnlyAReadIsLogged(): void
    {
        $listener = $this->listener();

        foreach (['POST', 'HEAD', 'PUT'] as $method) {
            $listener->onKernelException($this->event(Request::create('/missing', $method)));
            $listener->onKernelTerminate();
        }

        $listener->onKernelException($this->event(Request::create('/missing')));
        $listener->onKernelTerminate();
        self::assertCount(2, $this->log->recordedSoFar());
    }

    public function testNothingIsLoggedWithoutARecorder(): void
    {
        $listener = new NotFoundListener($this->resolver(), $this->clock, $this->repository);
        $listener->onKernelException($this->event(Request::create('/missing')));
        $listener->onKernelTerminate();

        self::assertSame([], $this->log->recordedSoFar());
    }

    public function testOtherExceptionsAndSubRequestsAreLeftAlone(): void
    {
        $this->repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')), true, new DateTimeImmutable()));
        $listener = $this->listener();

        $denied = $this->event(Request::create('/a'), new AccessDeniedHttpException());
        $listener->onKernelException($denied);
        $error = $this->event(Request::create('/a'), new RuntimeException());
        $listener->onKernelException($error);
        $sub = $this->event(Request::create('/a'), type: HttpKernelInterface::SUB_REQUEST);
        $listener->onKernelException($sub);
        $listener->onKernelTerminate();

        self::assertNull($denied->getResponse());
        self::assertNull($error->getResponse());
        self::assertNull($sub->getResponse());
        self::assertSame([], $this->repository->hitsRecorded());
        self::assertSame([], $this->log->recordedSoFar());
    }

    public function testAResetDropsWhatTheRequestKept(): void
    {
        $this->repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')), true, new DateTimeImmutable()));
        $listener = $this->listener();
        $listener->onKernelException($this->event(Request::create('/a')));
        $listener->onKernelException($this->event(Request::create('/missing')));

        $listener->reset();
        $listener->onKernelTerminate();

        self::assertSame([], $this->repository->hitsRecorded());
        self::assertSame([], $this->log->recordedSoFar());
    }

    public function testAFailedWriteIsLoggedAndDropped(): void
    {
        $failing = new InMemoryNotFoundLog();
        $failing->failing = true;

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (\is_string($level) ? $level : '').' '.$message.' '.(\is_string($context['message'] ?? null) ? $context['message'] : '').' '.(($context['exception'] ?? null) instanceof Throwable ? 'with exception' : '');
            }
        };
        $listener = new NotFoundListener($this->resolver(), $this->clock, null, new NotFoundRecorder($failing, $this->clock), 30, null, $logger);

        $listener->onKernelException($this->event(Request::create('/missing')));
        $listener->onKernelTerminate();
        $listener->onKernelTerminate();

        self::assertCount(1, $logger->messages);
        self::assertStringStartsWith('error The redirects could not record a request: {message} ', $logger->messages[0]);
        self::assertStringEndsWith(' with exception', $logger->messages[0]);
        self::assertStringContainsString('gone', $logger->messages[0]);

        $quiet = new NotFoundListener($this->resolver(), $this->clock, null, new NotFoundRecorder($failing, $this->clock));
        $quiet->onKernelException($this->event(Request::create('/missing')));
        $quiet->onKernelTerminate();
        self::assertSame([], $failing->recordedSoFar());
    }

    public function testTheRequestIsDroppedEvenWhenAWriteFailsOtherwise(): void
    {
        $this->log->failure = new LogicException('broken');
        $listener = $this->listener();
        $listener->onKernelException($this->event(Request::create('/missing')));

        try {
            $listener->onKernelTerminate();
            self::fail('Expected the failure');
        } catch (LogicException) {
        }

        $this->log->failure = null;
        $listener->onKernelTerminate();
        self::assertSame([], $this->log->recordedSoFar());
    }

    public function testTheLastHitIsThrottledToAMinuteByDefault(): void
    {
        $this->repository->save(new Redirect(new RedirectRule(SourcePattern::exact('/a'), Target::url('/b')), true, new DateTimeImmutable()));
        $listener = new NotFoundListener($this->resolver(), $this->clock, $this->repository);

        $listener->onKernelException($this->event(Request::create('/a')));
        $listener->onKernelTerminate();

        self::assertEquals([[1, $this->clock->now(), $this->clock->now()->modify('-60 seconds')]], $this->repository->hitsRecorded());
    }

    public function testAnOldDomainIsSentToItsNewHost(): void
    {
        $listener = new OldDomainListener(new OldDomains(['old.example' => new OldDomain('new.example', RedirectStatus::PermanentRedirect)]));
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = Request::create('https://old.example/sub/page?x=1', server: ['SCRIPT_FILENAME' => '/srv/public/index.php', 'SCRIPT_NAME' => '/sub/index.php', 'PHP_SELF' => '/sub/index.php']);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $listener->onKernelRequest($event);

        self::assertSame(308, $event->getResponse()?->getStatusCode());
        self::assertSame('https://new.example/sub/page?x=1', $event->getResponse()->headers->get('Location'));

        $other = new RequestEvent($kernel, Request::create('https://new.example/page'), HttpKernelInterface::MAIN_REQUEST);
        $listener->onKernelRequest($other);
        self::assertNull($other->getResponse());

        $sub = new RequestEvent($kernel, Request::create('https://old.example/page'), HttpKernelInterface::SUB_REQUEST);
        $listener->onKernelRequest($sub);
        self::assertNull($sub->getResponse());
    }

    /**
     * @param list<RedirectRule> $provided
     */
    private function listener(array $provided = []): NotFoundListener
    {
        return new NotFoundListener($this->resolver($provided), $this->clock, $this->repository, new NotFoundRecorder($this->log, $this->clock), 30);
    }

    /**
     * @param list<RedirectRule> $provided
     */
    private function resolver(array $provided = []): RedirectResolver
    {
        return new RedirectResolver(new RedirectTableCache([new StoredRedirects($this->repository), new ListProvider($provided)]), new LocationBuilder());
    }

    private function event(Request $request, ?Throwable $throwable = null, int $type = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return new ExceptionEvent(self::createStub(HttpKernelInterface::class), $request, $type, $throwable ?? new NotFoundHttpException());
    }
}
