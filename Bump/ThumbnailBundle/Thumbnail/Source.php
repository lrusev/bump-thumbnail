<?php

namespace Bump\ThumbnailBundle\Thumbnail;

use Symfony\Component\HttpFoundation\File\File;

class Source implements SourceInterface
{
    protected $id;
    protected $mimeType;
    protected $groupName;
    protected $path;

    public function __construct($path, $id = null, $mimeType = null, $groupName = 'common')
    {
        $this->path = $path;
        if (is_null($id) || is_null($mimeType)) {
            $file = new File($path, true);
            if (is_null($id)) {
                $id = $file->getFilename();
            }

            if (is_null($mimeType)) {
                $mimeType = $file->getMimeType();
            }
        }

        $this->id = $id;
        $this->mimeType = $mimeType;
        $this->groupName = $groupName;
    }

    /**
     * Gets the value of id.
     *
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Gets the value of mimeType.
     *
     * @return mixed
     */
    public function getMimeType()
    {
        return $this->mimeType;
    }

    /**
     * Sets the value of mimeType.
     *
     * @param mixed $mimeType the mime type
     *
     * @return self
     */
    public function setMimeType($mimeType)
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    /**
     * Gets the value of groupName.
     *
     * @return mixed
     */
    public function getGroupName()
    {
        return $this->groupName;
    }

    /**
     * Gets the value of path.
     *
     * @return mixed
     */
    public function getPath()
    {
        return $this->path;
    }
}
