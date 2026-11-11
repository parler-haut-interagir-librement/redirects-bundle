<?php

declare(strict_types=1);

use Phil\RedirectsBundle\Infrastructure\Http\NotFoundAdminController;
use Phil\RedirectsBundle\Infrastructure\Http\RedirectAdminController;
use Phil\RedirectsBundle\Infrastructure\Http\TransferAdminController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * The administration of the redirects, mounted by the application under a prefix of its choice, behind its firewall:
 *
 *     # config/routes/phil_redirects.yaml
 *     phil_redirects_admin:
 *         resource: '@PhilRedirectsBundle/config/routes/admin.php'
 *         prefix: /admin/redirects
 */
return static function (RoutingConfigurator $routes): void {
    $id = ['id' => '[1-9]\d{0,9}'];

    $routes->add(RedirectAdminController::LIST_ROUTE, '/')
        ->controller([RedirectAdminController::class, 'list'])
        ->methods(['GET']);

    $routes->add(RedirectAdminController::CREATE_ROUTE, '/new')
        ->controller([RedirectAdminController::class, 'create'])
        ->methods(['GET', 'POST']);

    $routes->add(RedirectAdminController::EDIT_ROUTE, '/{id}/edit')
        ->controller([RedirectAdminController::class, 'edit'])
        ->requirements($id)
        ->methods(['GET', 'POST']);

    $routes->add('phil_redirects_admin_toggle', '/{id}/toggle')
        ->controller([RedirectAdminController::class, 'toggle'])
        ->requirements($id)
        ->methods(['POST']);

    $routes->add('phil_redirects_admin_delete', '/{id}/delete')
        ->controller([RedirectAdminController::class, 'delete'])
        ->requirements($id)
        ->methods(['POST']);

    $routes->add(NotFoundAdminController::LIST_ROUTE, '/not-found')
        ->controller([NotFoundAdminController::class, 'list'])
        ->methods(['GET']);

    $routes->add('phil_redirects_admin_not_found_delete', '/not-found/{id}/delete')
        ->controller([NotFoundAdminController::class, 'delete'])
        ->requirements($id)
        ->methods(['POST']);

    $routes->add('phil_redirects_admin_export', '/export')
        ->controller([TransferAdminController::class, 'export'])
        ->methods(['GET']);

    $routes->add(TransferAdminController::IMPORT_ROUTE, '/import')
        ->controller([TransferAdminController::class, 'import'])
        ->methods(['GET', 'POST']);
};
