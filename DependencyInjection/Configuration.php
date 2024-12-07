<?php

namespace Ovrflo\ApiPlatformTypescriptGeneratorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('ovrflo_api_platform_typescript_generator');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('output_dir')
                    ->info('Directory to output generated files to')
                    ->defaultValue('%kernel.project_dir%/assets/api')
                ->end()
                ->scalarNode('api_prefix')
                    ->info('Prefix to use for API routes')
                    ->defaultNull()
                ->end()
                ->arrayNode('model_metadata')
                    ->children()
                        ->arrayNode('namespaces')
                            ->info('Namespaces to consider when generating models')
                            ->defaultValue(['App\\Entity'])
                            ->scalarPrototype()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('routes')
                    ->canBeDisabled()
                ->end()
                ->arrayNode('import_types')
                    ->info('Types to import into API')
                    ->stringPrototype()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
