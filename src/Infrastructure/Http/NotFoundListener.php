<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Http;

use DateInterval;
use Doctrine\DBAL\Exception as DatabaseException;
use Phil\RedirectsBundle\Application\NotFoundHit;
use Phil\RedirectsBundle\Application\NotFoundRecorder;
use Phil\RedirectsBundle\Application\RedirectResolver;
use Phil\RedirectsBundle\Application\Resolution;
use Phil\RedirectsBundle\Domain\Contract\RedirectRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Resolves the redirects when, and only when, a request was not found: the routing, the controllers — the slug
 * history of a content package among them — have all answered first, and a request that is found never reaches the
 * table.
 *
 * On `kernel.exception`, a `404` of the main request is resolved: a redirect answers with its status and location, a
 * `410 Gone` replaces the exception so that the application's error page renders it, and a request that nothing
 * matches is kept for the log. The writes — the hit of the redirect, the path not found — wait for `kernel.terminate`,
 * after the response has gone, and the state of the request is dropped on `kernel.reset`.
 */
final class NotFoundListener
{
    private ?int $hit = null;

    private ?NotFoundHit $notFound = null;

    public function __construct(
        private readonly RedirectResolver $resolver,
        private readonly ClockInterface $clock,
        private readonly ?RedirectRepository $hits = null,
        private readonly ?NotFoundRecorder $recorder = null,
        private readonly int $hitThrottle = 60,
        private readonly ?string $verdictHeader = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if (!$event->isMainRequest() || !$throwable instanceof HttpExceptionInterface || 404 !== $throwable->getStatusCode()) {
            return;
        }

        $request = $event->getRequest();
        $resolution = $this->resolver->resolve($request->getHost(), $request->getPathInfo(), $request->getQueryString() ?? '', $request->getLocale());

        if (!$resolution instanceof Resolution) {
            if ($this->recorder instanceof NotFoundRecorder && ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
                $this->notFound = new NotFoundHit(
                    $request->getHost(),
                    $request->getPathInfo(),
                    $request->headers->get('Referer'),
                    $request->headers->get('User-Agent'),
                    null === $this->verdictHeader ? null : $request->headers->get($this->verdictHeader),
                );
            }

            return;
        }

        $this->hit = $resolution->rule->id;

        if (null === $resolution->location) {
            $event->setThrowable(new GoneHttpException('The page is gone.', $event->getThrowable()));

            return;
        }

        $event->setResponse(new RedirectResponse($resolution->location, $resolution->status()->value));
        $event->allowCustomResponseCode();
    }

    public function onKernelTerminate(): void
    {
        try {
            $now = $this->clock->now();

            if (null !== $this->hit && $this->hits instanceof RedirectRepository) {
                $this->hits->recordHit($this->hit, $now, $now->sub(new DateInterval('PT'.$this->hitThrottle.'S')));
            }

            if ($this->notFound instanceof NotFoundHit && $this->recorder instanceof NotFoundRecorder) {
                $this->recorder->record($this->notFound);
            }
        } catch (DatabaseException $databaseException) {
            $this->logger?->error('The redirects could not record a request: {message}', ['message' => $databaseException->getMessage(), 'exception' => $databaseException]);
        } finally {
            $this->reset();
        }
    }

    public function reset(): void
    {
        $this->hit = null;
        $this->notFound = null;
    }
}
