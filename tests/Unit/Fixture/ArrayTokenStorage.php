<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Fixture;

use Override;
use SensitiveParameter;
use Symfony\Component\Security\Csrf\Exception\TokenNotFoundException;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

/**
 * CSRF tokens kept in memory, for the tests that have no session.
 */
final class ArrayTokenStorage implements TokenStorageInterface
{
    /** @var array<string, string> */
    private array $tokens = [];

    #[Override]
    public function getToken(string $tokenId): string
    {
        return $this->tokens[$tokenId] ?? throw new TokenNotFoundException();
    }

    #[Override]
    public function setToken(string $tokenId, #[SensitiveParameter] string $token): void
    {
        $this->tokens[$tokenId] = $token;
    }

    #[Override]
    public function removeToken(string $tokenId): ?string
    {
        $token = $this->tokens[$tokenId] ?? null;
        unset($this->tokens[$tokenId]);

        return $token;
    }

    #[Override]
    public function hasToken(string $tokenId): bool
    {
        return isset($this->tokens[$tokenId]);
    }
}
