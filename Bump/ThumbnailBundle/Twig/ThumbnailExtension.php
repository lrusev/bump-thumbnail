<?php
namespace Bump\ThumbnailBundle\Twig;

use Gedmo\Uploadable\MimeType\MimeTypesExtensionsMap;
use Imagine\Image\ImageInterface;
use Bump\CyberI2Bundle\Entity\Asset;
use Twig_Function_Method;
use Twig_Extension;
use Twig_SimpleFilter;
use Bump\ThumbnailBundle\Thumbnail\Generator;
use Bump\ThumbnailBundle\Thumbnail\SourceInterface;

class ThumbnailExtension extends Twig_Extension
{
    private $generator;

    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
    }

    public function getFilters()
    {
        return array(
            new Twig_SimpleFilter('mime_to_class', array($this, 'mimeToClass')),
            new Twig_SimpleFilter('asset_to_class', array($this, 'assetToClass')),
        );
    }

    public function getFunctions()
    {
        return array(
            'mime_to_class' => new Twig_Function_Method($this, 'mimeToClass'),
            'is_image_mime' => new Twig_Function_Method($this, 'isImage'),
            'is_video_mime' => new Twig_Function_Method($this, 'isVideo'),
            'asset_thumbnail' => new Twig_Function_Method($this, 'assetThumbnail'),
            'is_thumbnailable' => new Twig_Function_Method($this, 'isThunailable'),
        );
    }

    public function getName()
    {
        return 'thumbnail_extension';
    }

    public function isThunailable($format)
    {
        return $this->generator->supported($format);
    }

    public function assetThumbnail(SourceInterface $asset, $width, $height = null, $mode = ImageInterface::THUMBNAIL_INSET, $format = 'png', $quality = 75)
    {
        if (is_null($height)) {
            $height = $width;
        }

        return $this->generator->getThumbnailUrl($asset, $width, $height, $mode, $format, $quality);
    }

    public function assetToClass($asset, $prefix = 'icon-', $default = 'sprite-file-_default')
    {
        if (!$asset instanceof SourceInterface || $asset->getMimeType() === null) {
            return $default;
        }

        $classes = $this->mimeToClass($asset->getMimeType(), $prefix, $default);
        $ext = pathinfo($asset->getPath(), PATHINFO_EXTENSION);

        $exploded = explode(' ', $classes);
        $class = $prefix.$ext;
        if (substr($ext, -1, 1) == 'x') {
            $classX = substr($ext, 0, strlen($ext)-1);
            if (!in_array($classX, $exploded)) {
                $exploded[] = $prefix.$classX;
            }
        }

        if (!in_array($class, $exploded)) {
            $exploded[] = $class;
        }

        return implode(' ', $exploded);
    }

    public function mimeToClass($mimeType, $prefix = 'icon-', $default = 'sprite-file-_default')
    {
        if (empty($mimeType)) {
            return $default;
        }

        $classes = [];
        $map = MimeTypesExtensionsMap::$map;
        $parts = explode('/', $mimeType);
        $classes[] = '_'.$parts[0];
        $classes[] =  str_replace('.', '', $parts[1]);

        if (isset($map[$mimeType])) {
            $classes[] = $map[$mimeType];
        }

        $classes = array_unique($classes);

        return implode(
            ' ',
            array_merge(
                [$default],
                array_map(
                    function ($class) use ($prefix) {
                         return $prefix.$class;
                    },
                    $classes
                )
            )
        );
    }

    public function isImage($mimeType, array $allowed = array('image/png', 'image/jpg', 'image/jpeg', 'image/gif'))
    {
        return $this->generator->isImage($mimeType, $allowed);
    }

    public function isVideo($mimeType, array $allowed = array())
    {
        return $this->generator->isVideo($mimeType, $allowed);
    }
}
