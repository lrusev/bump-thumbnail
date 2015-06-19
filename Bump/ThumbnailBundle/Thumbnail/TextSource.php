<?php

namespace Bump\ThumbnailBundle\Thumbnail;

use InvalidArgumentException;

class TextSource extends Source
{
    const TYPE = 'TEXT';

    protected $text;

    public function __construct($text, $id = null, $groupName = 'common')
    {
        if (empty($text)) {
            throw new InvalidArgumentException("Expected not empty text");
        }

        $this->text = $text;
        $this->path = md5($text);
        $this->id = $id;
        $this->mimeType = 'text/plain';
        $this->groupName = $groupName;
    }

    /**
     * Gets the value of text.
     *
     * @return mixed
     */
    public function getText()
    {
        return $this->text;
    }
}
