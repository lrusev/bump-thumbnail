<?php
namespace Bump\ThumbnailBundle\Thumbnail;

interface SourceInterface
{
    public function getId();
    public function getPath();
    public function getMimeType();
    public function getGroupName();
}
