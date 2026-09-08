<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

use Flarum\Extend;
use FlatRate\FlarumMediaPrivacy\Listener\OpaqueUploadBasename;
use FoF\Upload\Events\File\IsSlugged;

if (!class_exists(IsSlugged::class)) {
    return [];
}

return [
    (new Extend\Event())
        ->listen(IsSlugged::class, OpaqueUploadBasename::class),
];
