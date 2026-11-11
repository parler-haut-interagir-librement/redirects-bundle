<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Infrastructure;

use EasyCorp\Bundle\EasyAdminBundle\Config\Menu\RouteMenuItem;
use Phil\RedirectsBundle\Application\NotFoundHit;
use Phil\RedirectsBundle\Infrastructure\BotDetection\RobotVerdictFilter;
use Phil\RedirectsBundle\Infrastructure\EasyAdmin\RedirectsAdmin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

#[CoversClass(RedirectsAdmin::class)]
#[CoversClass(RobotVerdictFilter::class)]
final class BridgesTest extends TestCase
{
    public function testTheMenuOfAnEasyAdminDashboard(): void
    {
        $items = array_map(static fn (RouteMenuItem $item): array => [
            $item->getAsDto()->getLabel() instanceof TranslatableMessage ? $item->getAsDto()->getLabel()->getMessage() : '',
            $item->getAsDto()->getLabel() instanceof TranslatableMessage ? $item->getAsDto()->getLabel()->getDomain() : '',
            $item->getAsDto()->getIcon(),
            $item->getAsDto()->getRouteName(),
        ], RedirectsAdmin::menuItems());

        self::assertSame([
            ['menu.redirects', 'phil_redirects', 'fa fa-route', 'phil_redirects_admin_list'],
            ['menu.not_found', 'phil_redirects', 'fa fa-circle-question', 'phil_redirects_admin_not_found'],
            ['menu.import', 'phil_redirects', 'fa fa-file-import', 'phil_redirects_admin_import'],
        ], $items);
    }

    public function testTheRobotVerdictIsReadStrictly(): void
    {
        $filter = new RobotVerdictFilter();

        self::assertTrue($filter->ignores(new NotFoundHit('h', '/', verdict: 'robot')));
        self::assertFalse($filter->ignores(new NotFoundHit('h', '/', verdict: 'human')));
        self::assertFalse($filter->ignores(new NotFoundHit('h', '/', verdict: 'Robot')));
        self::assertFalse($filter->ignores(new NotFoundHit('h', '/')));
    }
}
