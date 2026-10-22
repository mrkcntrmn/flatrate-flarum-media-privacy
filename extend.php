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

$extenders = [
    (new Extend\Event())
        ->listen(IsSlugged::class, OpaqueUploadBasename::class),
];

if (class_exists(\FoF\Upload\Events\File\WillBeDownloaded::class)) {
    $extenders[] = (new Extend\Event())
        ->listen(\FoF\Upload\Events\File\WillBeDownloaded::class, \FlatRate\FlarumMediaPrivacy\Listener\PrivateDownloadGuard::class);
}

return $extenders;
