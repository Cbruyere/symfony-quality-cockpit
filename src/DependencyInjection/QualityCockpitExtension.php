<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class QualityCockpitExtension extends Extension
{
    /** @param array<int, array<string, mixed>> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('quality_cockpit.enabled', $config['enabled']);
        $container->setParameter('quality_cockpit.route_prefix', $config['route_prefix']);
        $container->setParameter('quality_cockpit.reports', $config['reports']);
        $container->setParameter('quality_cockpit.thresholds', $config['thresholds']);
        $container->setParameter('quality_cockpit.freshness', $config['freshness']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');
    }
}
