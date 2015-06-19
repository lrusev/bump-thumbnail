<?php

namespace Bump\ThumbnailBundle\Thumbnail;

use InvalidArgumentException;

class UrlSource extends Source
{
    protected $url;

    public function __construct($url, $id = null, $groupName = 'common')
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid url: {$url}");
        }

        $this->url = $url;
        $this->path = md5($url);
        $this->id = $id;
        $this->mimeType = $url;
        $this->groupName = $groupName;
    }

    /**
     * Gets the value of url.
     *
     * @return mixed
     */
    public function getUrl()
    {
        return $this->url;
    }
}
