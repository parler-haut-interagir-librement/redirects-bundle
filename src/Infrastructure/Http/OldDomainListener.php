<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\Http;

use Phil\RedirectsBundle\Application\OldDomain;
use Phil\RedirectsBundle\Application\OldDomains;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Sends a request to an old domain to its new host before the router reads it: a domain the application left is
 * mapped as a whole, without a redirect per page. Registered only when old domains are configured.
 */
final readonly class OldDomainListener
{
    public function __construct(
        private OldDomains $domains,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $domain = $this->domains->find($request->getHost());

        if (!$domain instanceof OldDomain) {
            return;
        }

        $location = $domain->locationFor($request->getScheme(), $request->getBaseUrl().$request->getPathInfo(), $request->getQueryString() ?? '');
        $event->setResponse(new RedirectResponse($location, $domain->status->value));
    }
}
