<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Infrastructure\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Menu\RouteMenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Phil\RedirectsBundle\Infrastructure\Http\NotFoundAdminController;
use Phil\RedirectsBundle\Infrastructure\Http\RedirectAdminController;
use Phil\RedirectsBundle\Infrastructure\Http\TransferAdminController;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The administration of the redirects reached from an EasyAdmin back office: its pages keep their own routes, role,
 * forms and layout — the bundle's `admin.layout`, which the application points to a template of its back office —, and
 * the dashboard's menu links to them:
 *
 *     public function configureMenuItems(): iterable
 *     {
 *         yield MenuItem::section('Redirects');
 *         yield from RedirectsAdmin::menuItems();
 *     }
 */
final class RedirectsAdmin
{
    private function __construct()
    {
    }

    /**
     * The links to the list of redirects, the 404 log and the import, labelled in the `phil_redirects` domain.
     *
     * @return list<RouteMenuItem>
     */
    public static function menuItems(): array
    {
        return [
            MenuItem::linkToRoute(new TranslatableMessage('menu.redirects', [], 'phil_redirects'), 'fa fa-route', RedirectAdminController::LIST_ROUTE),
            MenuItem::linkToRoute(new TranslatableMessage('menu.not_found', [], 'phil_redirects'), 'fa fa-circle-question', NotFoundAdminController::LIST_ROUTE),
            MenuItem::linkToRoute(new TranslatableMessage('menu.import', [], 'phil_redirects'), 'fa fa-file-import', TransferAdminController::IMPORT_ROUTE),
        ];
    }
}
