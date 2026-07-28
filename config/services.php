<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services->load('Chrisdev\\QualityCockpit\\', __DIR__.'/../src/')
        ->exclude(__DIR__.'/../src/{DependencyInjection,Tests}');
};
