<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\BotDetection;

use Override;
use Phil\BotDetectionBundle\Domain\Verdict\Verdict;
use Phil\RedirectsBundle\Application\Contract\NotFoundFilter;
use Phil\RedirectsBundle\Application\NotFoundHit;

/**
 * Keeps out of the log a request whose browser reported the robot verdict of `phil/bot-detection-bundle`, read
 * strictly from the header the configuration names. The verdict decides this request only and is not kept.
 */
final readonly class RobotVerdictFilter implements NotFoundFilter
{
    #[Override]
    public function ignores(NotFoundHit $hit): bool
    {
        return Verdict::fromSubmitted($hit->verdict)->isRobot();
    }
}
