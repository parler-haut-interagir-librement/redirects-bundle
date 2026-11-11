<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Fixture;

use Override;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The roles of the user, read from the `X-Roles` header of the current request, or given once.
 */
final readonly class RoleChecker implements AuthorizationCheckerInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private ?RequestStack $requests = null,
        private array $roles = [],
    ) {
    }

    #[Override]
    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
    {
        $header = $this->requests?->getCurrentRequest()?->headers->get('X-Roles');
        $roles = null === $header ? $this->roles : explode(',', $header);

        return \in_array($attribute, $roles, true);
    }
}
