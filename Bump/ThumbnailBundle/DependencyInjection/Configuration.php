<?php

namespace Bump\ThumbnailBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('bump_thumbnail');

        $rootNode
            ->children()
                ->scalarNode('default_image')->cannotBeEmpty()->defaultValue('@BumpThumbnailBundle/Resources/public/images/image-loading.png')->end()
                ->scalarNode('thumbnail_relative_path')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('ffmpeg_binary')->cannotBeEmpty()->defaultValue('/usr/bin/ffmpeg')->end()
                ->scalarNode('ffprobe_binary')->cannotBeEmpty()->defaultValue('/usr/bin/ffprobe')->end()
                ->scalarNode('binary_timeout')->defaultValue(60)->end()
                ->scalarNode('threads_count')->defaultValue(4)->end()
                ->scalarNode('html2any_base_url')
                            ->defaultValue('http://html2any.pdc.org/api')
                            ->validate()
                                ->ifTrue(
                                    function ($v) {
                                        return !is_null($v) && !filter_var($v, FILTER_VALIDATE_URL);
                                    }
                                )
                                ->thenInvalid('Expected Valid html2any URL address.')
                            ->end()
                        ->end()
                ->scalarNode('html2any_username')->end()
                ->scalarNode('html2any_password')->end()
                ->arrayNode('default_thumbnails')
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->useAttributeAsKey('name')
                        ->prototype('variable')
                        ->end()
                    ->end()
                ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
