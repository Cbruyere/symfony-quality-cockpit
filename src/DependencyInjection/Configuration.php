<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('quality_cockpit');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('route_prefix')->defaultValue('/_quality')->cannotBeEmpty()->end()
                ->arrayNode('reports')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('base_directory')->defaultValue('%kernel.project_dir%/var/reports')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('thresholds')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('phpunit')->addDefaultsIfNotSet()->children()
                            ->integerNode('excellent')->defaultValue(90)->min(0)->max(100)->end()
                            ->integerNode('good')->defaultValue(80)->min(0)->max(100)->end()
                            ->integerNode('warning')->defaultValue(65)->min(0)->max(100)->end()
                            ->integerNode('degraded')->defaultValue(50)->min(0)->max(100)->end()
                        ->end()->end()
                        ->arrayNode('infection')->addDefaultsIfNotSet()->children()
                            ->integerNode('excellent')->defaultValue(100)->min(0)->max(100)->end()
                            ->integerNode('very_good')->defaultValue(95)->min(0)->max(100)->end()
                            ->integerNode('good')->defaultValue(85)->min(0)->max(100)->end()
                            ->integerNode('warning')->defaultValue(70)->min(0)->max(100)->end()
                            ->integerNode('degraded')->defaultValue(60)->min(0)->max(100)->end()
                        ->end()->end()
                        ->arrayNode('phpmetrics')->addDefaultsIfNotSet()->children()
                            ->integerNode('warning_classes')->defaultValue(1)->min(0)->end()
                            ->integerNode('degraded_classes')->defaultValue(5)->min(0)->end()
                            ->integerNode('critical_classes')->defaultValue(15)->min(0)->end()
                        ->end()->end()
                    ->end()
                ->end()
                ->arrayNode('freshness')->addDefaultsIfNotSet()->children()
                    ->integerNode('warning_after_hours')->defaultValue(24)->min(0)->end()
                    ->integerNode('stale_after_hours')->defaultValue(72)->min(0)->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
