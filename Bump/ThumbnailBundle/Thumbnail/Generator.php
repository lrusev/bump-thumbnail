<?php

namespace Bump\ThumbnailBundle\Thumbnail;

use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use RuntimeException;
use Exception;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Bump\RestBundle\Library\Url;
use FFMpeg\Coordinate\TimeCode;
use Hyperlight\Hyperlight;
use Hyperlight\languages\PhpLanguage as HyperLanguage;
use Symfony\Component\HttpFoundation\Response;

class Generator
{
    use Url;

    const TMP_DIR = '_';
    const SAVE_PARAM = 'save_path';
    const COPY_EXT = 'ext';
    const ID_PARAM = 'id';
    const GROUP_PARAM = 'group';
    const REMOTE_TIMEOUT = 300;
    const DEFAULT_QUALITY = 75;
    const DEFAULT_DOCUMENT_WIDTH = 1024;

    private static $imageMimeTypes = array(
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'png'  => 'image/png',
    );

    private static $videoMimeTypes = array(
        'application/ogg',
        'video/avi',
        'video/mpeg',
        'video/mp4',
        'video/ogg',
        'video/quicktime',
        'video/webm',
        'video/x-ms-wmv',
        'video/x-flv',
    );

    private static $officeMimeTypes = array(
        'application/vnd.ms-powerpoint',
        'application/vnd.ms-office',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/x-php',
    );

    private $basePath;
    private $relativePath;
    private $container;
    private $defaultImagePath;

    public function __construct(ContainerInterface $container, $relativePath)
    {
        $this->container = $container;
        $this->relativePath = trim($relativePath, '/');
        $kernel = $this->container->get('kernel');
        $webDir = realpath($kernel->getRootDir().'/../public_html');
        if (!$webDir) {
            throw new RuntimeException("Expected document root to be at path: ".$this->container->get('kernel')->getRootDir().'/../public_html');
        }

        $this->basePath = $webDir.'/'.$this->relativePath;
        $this->defaultImagePath = $kernel->locateResource($this->container->getParameter('bump_thumbnail.default_image'));
    }

    public function clearThumbnails(SourceInterface $source)
    {
        $fs  = new Filesystem();
        $dir = $this->getTopSavePathname($source);
        if ($fs->exists($dir)) {
            $fs->remove($dir);
        }
    }

    public function generateAsResponse(SourceInterface $source, $width, $height = null, $mode = ImageInterface::THUMBNAIL_INSET, $format = 'png', $quality = self::DEFAULT_QUALITY, $force = false, $quiet = true)
    {
        return $this->generate($source, $width, $height, $mode, $format, $quality, $force, $quiet, true);
    }

    public function generate(SourceInterface $source, $width, $height = null, $mode = ImageInterface::THUMBNAIL_INSET, $format = 'png', $quality = self::DEFAULT_QUALITY, $force = false, $quiet = true, $asResponse = false)
    {
        if (is_null($height)) {
            $height = $width;
        }

        try {
            $fs  = new Filesystem();
            $imagine = new Imagine();

            $savePathname = $this->getSavePathname($source, $width, $height, $mode, $format, $quality);
            if (!$force && $fs->exists($savePathname)) {
                if ($asResponse) {
                    return $this->getResponse($savePathname, $format);
                }

                return $imagine->open($savePathname);
            }

            if (!$fs->exists(dirname($savePathname))) {
                $fs->mkdir(dirname($savePathname), 0755);
            }

            $path = $source->getPath();
            $mimeType = $source->getMimeType();
            //processing video
            if ($this->isVideo($mimeType)) {
                $framePath = $this->getSaveTmpPathname($savePathname, 'frame.jpg');
                if (!$fs->exists($framePath)) {
                    if (!$fs->exists(dirname($framePath))) {
                        $fs->mkdir(dirname($framePath));
                    }
                    $ffprobe = $this->container->get('bump_thumbnail.ffprobe');
                    $duration = (float) $ffprobe->format($path)->get('duration');
                    if ($duration <= 1) {
                        $time = TimeCode::fromString("00:00:00:00");
                    } elseif ($duration<5) {
                        $time = TimeCode::fromSeconds(1);
                    } else {
                        $time = TimeCode::fromSeconds(5);
                    }
                    $ffmpeg = $this->container->get('bump_thumbnail.ffmpeg');
                    $video = $ffmpeg->open($path);
                    $frame = $video->frame($time);
                    $frame->save($framePath);
                }
                $path = $framePath;
            }

            //processing external
            if ($this->isExternal($mimeType)) {
                $highlighted = false;
                $highlightedCode = null;

                $isUrl = $source instanceof UrlSource;
                $isText = $source instanceof TextSource;

                if ($isUrl || $isText) {
                    $ext = 'html';
                    $convertedPath = $this->getSaveTmpPathname($savePathname, $path.'.jpg');
                } else {
                    $ext = pathinfo($path, PATHINFO_EXTENSION);
                    $convertedPath = $this->getSaveTmpPathname($savePathname, basename($path, '.'.$ext).'.jpg');
                    if (($lang = $this->getHighligherLangFromExt($ext))) {
                        $content = file_get_contents($path);
                        if ($ext == 'json') {
                            $data = json_decode($content, true);
                            if ($data) {
                                $content = json_encode($data, JSON_PRETTY_PRINT|JSON_NUMERIC_CHECK| JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                            }
                        }
                        $highlightedCode = $this->highlightCode($content, $lang);
                        $highlighted = true;
                        $ext = 'html';
                    }
                }

                if ($force || !$fs->exists($convertedPath)) {
                    if (!$fs->exists(dirname($convertedPath))) {
                        $fs->mkdir(dirname($convertedPath));
                    }

                    //call remote converter
                    $copyPath = $convertedPath.'.'.$ext;
                    if ($force || !$fs->exists($copyPath) || (time() - filemtime($copyPath)) >= self::REMOTE_TIMEOUT) {
                        $fs->remove($copyPath);
                        if ($isUrl) {
                            $fs->touch($copyPath);
                        } elseif ($isText) {
                            $fs->dumpFile($copyPath, $this->wrapRawText($source->getText()));
                        } else {
                            if ($highlighted && $highlightedCode) {
                                $fs->dumpFile($copyPath, $highlightedCode);
                            } else {
                                $fs->copy($path, $copyPath);
                            }
                        }

                        $relativePathname = $this->getSavePathname($source, $width, $height, $mode, $format, $quality, false);
                        $relativePathname = $this->getSaveTmpPathname($relativePathname, basename($convertedPath).'.'.$ext);

                        if ($isUrl) {
                            $accessUrl = $source->getUrl();
                        } else {
                            $accessUrl = $this->getUrl(
                                $this->container->get('router')->getContext(),
                                $relativePathname
                            );
                        }

                        $encryptor = $this->container->get('bump_api.encryptor');
                        $hash = $encryptor->encrypt(
                            json_encode(
                                [
                                    self::SAVE_PARAM => $convertedPath,
                                    self::ID_PARAM => $source->getId(),
                                    self::GROUP_PARAM => $source->getGroupName(),
                                    self::COPY_EXT => $ext,
                                ]
                            )
                        );
                        $callbackUrl = $this->container->get('router')->generate('bump_thumbnailer_save', ['hash' => $hash], true);
                        $converter = $this->container->get('bump_thumbnail.html2any');

                        if ($this->isPDF($mimeType)) {
                            $converter->convertPDFToImage($accessUrl, $callbackUrl);
                        } elseif (($highlighted && $highlightedCode) || $this->isXML($mimeType) || $this->isHTML($mimeType) || $isUrl) {
                            $converter->convertHtmlToImage($accessUrl, $callbackUrl, ['cropH' => self::DEFAULT_DOCUMENT_WIDTH]);
                        } else {
                            $converter->convertOfficeToImage($accessUrl, $callbackUrl);
                        }
                    }

                    if (!$quiet) {
                        throw new RuntimeException("External converter call.");
                    }

                    return $this->generateDefault($width, $height, $mode, $format, $asResponse);
                }

                $path = $convertedPath;
            }

            $size  = new Box((int) $width, (int) $height);
            $image = $imagine->open($path);

            $thumbnail = $image->thumbnail($size, $mode)
                      ->save($savePathname, $this->getQualityOptions($format, $quality));
            if (!$asResponse) {
                return $thumbnail;
            }

            return $this->getResponse($savePathname, $format);
        } catch (Exception $e) {
            $this->container->get('logger')->error($e->getMessage(), ['exception' => $e]);
            if (!$quiet) {
                throw $e;
            }

            return $this->generateDefault($width, $height, $mode, $format, $asResponse);
        }
    }

    protected function getResponse($filepath, $format)
    {
        return new Response(
            file_get_contents($filepath),
            200,
            ['Content-type' => $this->getMimeType($format)]
        );
    }

    //hack for highlighter to avoid exception
    protected function getHighligherLangFromExt($ext)
    {
        $ext = strtolower($ext);
        if (in_array($ext, ['php', 'sql', 'xml', 'css', 'js', 'ini', 'sh', 'json', 'twig'])) {
            if ($ext == 'js' || $ext == 'json') {
                $lang = 'javascript';
            } elseif ($ext == 'sh') {
                $lang = 'shell';
            } else {
                $lang = $ext;
            }

            return $lang;
        }

        return;
    }

    protected function generateDefault($width, $height, $mode = ImageInterface::THUMBNAIL_INSET, $format = 'png', $asResponse = false)
    {
        $quality = self::DEFAULT_QUALITY;
        $imagine = new Imagine();
        $fs  = new Filesystem();

        if (!$fs->exists($this->defaultImagePath)) {
            throw new RuntimeException("There no image found at path: {$this->defaultImagePath}");
        }

        $source = new Source($this->defaultImagePath, '_default');

        $savePathname = $this->getSavePathname($source, $width, $height, $mode, $format, $quality);
        if ($fs->exists($savePathname)) {
            if ($asResponse) {
                return $this->getResponse($savePathname, $format);
            }

            return $imagine->open($savePathname);
        }

        if (!$fs->exists(dirname($savePathname))) {
            $fs->mkdir(dirname($savePathname), 0755);
        }

        $size  = new Box((int) $width, (int) $height);
        $image = $imagine->open($source->getPath());

        $thumbnail = $image->thumbnail($size, $mode)
                      ->save($savePathname, $this->getQualityOptions($format, $quality));

        if (!$asResponse) {
            return $thumbnail;
        }

        return $this->getResponse($savePathname, $format);
    }

    public function generateThumbnails(SourceInterface $source, array $thumbnails = array())
    {
        if (empty($thumbnails)) {
            $thumbnails = $this->container->getParameter('bump_thumbnail.default_thumbnails');
        }

        $logger = $this->container->get('logger');
        foreach ($thumbnails as $name => $thumbnail) {
            if (!isset($thumbnail['size']) || count($thumbnail['size']) != 2) {
                continue;
            }

            list($width, $height) = $thumbnail['size'];
            if (!is_numeric($width) || !is_numeric($height)) {
                continue;
            }

            if (isset($thumbnail['mode'])) {
                $mode = $thumbnail['mode'];
            } else {
                $mode = ImageInterface::THUMBNAIL_INSET;
            }

            if (isset($thumbnail['quality'])) {
                $quality = $thumbnail['quality'];
            } else {
                $quality = self::DEFAULT_QUALITY;
            }

            if (isset($thumbnail['format'])) {
                $format = $thumbnail['format'];
            } else {
                $format = 'png';
            }

            try {
                $this->generate($source, $width, $height, $mode, $format, $quality, true, false);
                $logger->info("Generated thumbnail {$name} from {$source->getPath()}");
            } catch (Exception $e) {
                $logger->error("Can't generate thumbnail {$name} from {$source->getPath()}");
                $logger->error($e->getMessage(), ['exception' => $e]);
                continue;
            }
        }
    }

    protected function getSaveTmpPathname($pathname, $filename = null)
    {
        return dirname($pathname).'/'.self::TMP_DIR.'/'.($filename ? $filename : basename($pathname));
    }

    protected function getTopSavePathname(SourceInterface $source)
    {
        $id = $source->getId();
        $groupName = $source->getGroupName();

        if (empty($id)) {
            throw new RuntimeException("Source id shouldn't be empty");
        }

        if (empty($groupName)) {
            throw new RuntimeException("Source group name shouldn't be empty");
        }

        $basePath = $this->basePath;

        return sprintf("%s/%s/%s", $basePath, $groupName, $id);
    }

    protected function getSavePathname(SourceInterface $source, $width, $height, $mode = ImageInterface::THUMBNAIL_INSET, $format = 'png', $quality = self::DEFAULT_QUALITY, $absolute = true)
    {
        $id = $source->getId();
        $groupName = $source->getGroupName();

        if (empty($id)) {
            throw new RuntimeException("Source id shouldn't be empty");
        }

        if (empty($groupName)) {
            throw new RuntimeException("Source group name shouldn't be empty");
        }

        $format = $this->normalizeFormat($format);
        $hash = [];
        if ($source instanceof UrlSource || $source instanceof TextSource) {
            $filename = $source->getPath();
        } else {
            $filename = pathinfo($source->getPath(), PATHINFO_FILENAME);
        }

        if (strlen($filename)<32) {
            $filename = md5($filename);
        }

        $hash = implode('/', str_split($filename, 12));

        if ($absolute) {
            $basePath = $this->basePath;
        } else {
            $basePath = $this->relativePath;
        }

        return sprintf("%s/%s/%s/%s/%dx%d-%s-q%d.%s", $basePath, $groupName, $id, $hash, $width, $height, $mode, $quality, $format);
    }

    public function getThumbnailUrl(SourceInterface $source, $width, $height, $mode = ImageInterface::THUMBNAIL_INSET, $format = 'png', $quality = self::DEFAULT_QUALITY)
    {
        try {
            $thumbnail = $this->generate($source, $width, $height, $mode, $format, $quality, false, false);
            $relativePath = $this->getSavePathname($source, $width, $height, $mode, $format, $quality, false);

            return $this->getUrl(
                $this->container->get('router')->getContext(),
                $relativePath
            );
        } catch (Exception $e) {
            $thumbnail = $this->generateDefault($width, $height, $mode, $format);
            $source = new Source($this->defaultImagePath, '_default');
            $relativePath = $this->getSavePathname($source, $width, $height, $mode, $format, $quality, false);

            return $this->getUrl(
                $this->container->get('router')->getContext(),
                $relativePath
            );
        }
    }

    public function getQualityOptions($format, $quality)
    {
        $options = [];
        $format = $this->normalizeFormat($format);

        if ($format == 'png') {
            if ($quality>10) {
                $quality = round($quality/10);
            }

            $options['png_compression_level'] = $quality;
        } elseif ($format == 'jpeg') {
            if ($quality<10) {
                $quality *= 10;
            }
            $options['jpeg_quality'] = $quality;
        }

        return $options;
    }

    public function normalizeQuality($format, $quality)
    {
        $format = $this->normalizeFormat($format);

        if ($format == 'png') {
            if ($quality>10) {
                $quality = round($quality/10);
            }
        } elseif ($format == 'jpeg') {
            if ($quality<10) {
                $quality *= 10;
            }
        }

        return $quality;
    }

    public function supported($format = null)
    {
        if (null === $format) {
            return array_keys(self::$imageMimeTypes);
        }

        $mimeType = null;
        if ($format instanceof SourceInterface) {
            $mimeType = $format->getMimeType();
        } elseif (is_string($format)) {
            if (strpos($format, '/') !== false) {
                $mimeType = $format;
            } elseif ($this->isURL($format)) {
                return true;
            }
        }

        if (!is_null($mimeType)) {
            if ($this->isImage($mimeType)) {
                return true;
            }

            if ($this->isVideo($mimeType)) {
                return true;
            }

            if ($this->isExternal($mimeType)) {
                return true;
            }

            return false;
        }

        return isset(self::$imageMimeTypes[$this->normalizeFormat($format)]);
    }

    public function getMimeType($format)
    {
        $format = $this->normalizeFormat($format);

        if (!$this->supported($format)) {
            throw new RuntimeException('Invalid format');
        }

        return self::$imageMimeTypes[$format];
    }

    public function normalizeFormat($format)
    {
        $format = strtolower($format);

        if ('jpg' === $format || 'pjpeg' === $format) {
            $format = 'jpeg';
        }

        return $format;
    }

    public function isExternal($mimeType)
    {
        return
            $this->isPDF($mimeType)
            || $this->isOffice($mimeType)
            || $this->isXML($mimeType)
            || $this->isHTML($mimeType)
            || $this->isURL($mimeType)
            || $this->isRawText($mimeType);
    }

    public function isRawText($mimeType)
    {
        return $mimeType == TextSource::TYPE;
    }

    public function isURL($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL);
    }

    public function isXML($mimeType)
    {
        return $mimeType == 'application/xml';
    }

    public function isHTML($mimeType)
    {
        return in_array(
            $mimeType,
            [
                'text/html',
                'text/webviewhtml'
            ]
        );
    }

    public function isPDF($mimeType)
    {
        return $mimeType == 'application/pdf';
    }

    public function isOffice($mimeType, array $allowed = array())
    {
        if (empty($allowed)) {
            $allowed = self::$officeMimeTypes;
        }

        if (empty($mimeType)) {
            return false;
        }

        return in_array($mimeType, $allowed);
    }

    public function isVideo($mimeType, array $allowed = array())
    {
        if (empty($allowed)) {
            $allowed = self::$videoMimeTypes;
        }

        if (empty($mimeType)) {
            return false;
        }

        return in_array($mimeType, $allowed);
    }

    public function isImage($mimeType, array $allowed = array())
    {
        if (empty($allowed)) {
            $allowed = array_merge(['image/jpg'], array_values(self::$imageMimeTypes));
        }

        if (empty($mimeType)) {
            return false;
        }

        if (strstr($mimeType, '/', true) === 'image') {
            if (!empty($allowed) && !in_array($mimeType, $allowed)) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Gets the value of basePath.
     *
     * @return mixed
     */
    public function getBasePath()
    {
        return $this->basePath;
    }

    protected function wrapRawText($text, $textAlign = 'justify', $maxWidth = self::DEFAULT_DOCUMENT_WIDTH, $marginTopBottom = '5px', $marginLeftRight = '5px')
    {
        if (is_int($maxWidth)) {
            $maxWidth .= 'px';
        }

        if (is_int($marginTopBottom)) {
            $marginTopBottom .= 'px';
        }

        if (is_null($marginLeftRight)) {
            $marginLeftRight .= 'px';
        }

        return
<<<HTML
<html>
<head>
    <title></title>
</head>
<body>
    <p style="text-align:{$textAlign};max-width:{$maxWidth};margin:{$marginTopBottom} {$marginLeftRight}">
        {$text}
    </p>
</body>
</html>
HTML;
    }

    protected function highlightCode($code, $lang, $name = null, $maxWidth = self::DEFAULT_DOCUMENT_WIDTH)
    {
        if (is_int($maxWidth)) {
            $maxWidth .= 'px';
        }
        /*if (!($lang = HyperLanguage::nameFromExt($ext))) {
            return $this->wrapRawText($code);
        }*/
        $er = error_reporting();
        error_reporting(0);

        try {
            $hyperlight = new Hyperlight($lang);
        } catch (Exception $e) {
            return $this->wrapRawText($code);
        }

        $header = <<<HEADER
<html>
<head>
    <title></title>
    <style type="text/css">.source-code{background:#222;color:#888;white-space:pre;padding:1em;max-width: {$maxWidth}; word-wrap:break-word;}.source-code.none{color:#996}.source-code .keyword{color:#f60}.source-code .keyword.builtin,.source-code .keyword.literal,.source-code .keyword.type{color:#fc0}.source-code .keyword.operator{color:#f60}.source-code .preprocessor{color:#996}.source-code .comment{color:#93c}.source-code .comment .doc{color:#399}.source-code .identifier{color:#eee}.source-code .char,.source-code .string{color:#6f0}.source-code .escaped{color:#aaa}.source-code .number,.source-code .tag{color:#f6e}.source-code .regex{color:#4bc}.source-code .operator{color:#888}.source-code .attribute{color:#f60}.source-code .tag,.source-code .tag .identifier,.source-code .variable,.source-code .variable .identifier{color:#4bc}.source-code .whitespace{background:#333}.source-code .error{border-bottom:1px solid red}.source-code.xml{color:#ddd}.source-code.xml .attribute,.source-code.xml .tag{color:#888}.source-code.xml .preprocessor .keyword{color:#996}.source-code.xml .meta,.source-code.xml .meta .keyword{color:#399}.source-code.cpp .preprocessor .identifier{color:#996}</style>
</head>
<body>
HEADER;
        if (!empty($name)) {
            $header .= "<h2>{$name}</h2>";
        }

        $header .= <<<BODY
   <pre class="source-code {$lang}">
BODY;
        try {
            $header .= $hyperlight->render($code);
        } catch (Exception $e) {
            $header .= $code;
        }
        $header .= <<<BODY
   </pre>
</body>
</html>
BODY;

        error_reporting($er);

        return $header;
    }
}
