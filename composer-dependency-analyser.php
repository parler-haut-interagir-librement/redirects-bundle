<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/**
 * The bundle's declared dependencies, checked against the bundle installed on its own.
 *
 * The console, Twig, the security, the translator, EasyAdmin and phil/bot-detection-bundle are used by the production
 * code but only required in development and suggested: the bundle registers the commands, the administration, its
 * menu and the robot verdict filter only when their packages are there. The `service()` function of the container
 * configurator is not seen as declared by `symfony/dependency-injection` although that package defines it.
 */
return new Configuration()
    ->addPathToScan(__DIR__.'/src', isDev: false)
    ->addPathToScan(__DIR__.'/tests', isDev: true)
    ->ignoreErrorsOnPackages([
        'easycorp/easyadmin-bundle',
        'phil/bot-detection-bundle',
        'symfony/console',
        'symfony/security-core',
        'symfony/security-csrf',
        'symfony/translation',
        'twig/twig',
    ], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreUnknownFunctions([
        'Symfony\Component\DependencyInjection\Loader\Configurator\service',
    ]);
