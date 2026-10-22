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

if (class_exists(\FoF\Upload\Commands\DownloadHandler::class)
    && method_exists(\FoF\Upload\Commands\DownloadHandler::class, 'prependDownloader')) {
    $extenders[] = (new Extend\ServiceProvider())
        ->register(\FlatRate\FlarumMediaPrivacy\MediaPrivacyServiceProvider::class);
}

return $extenders;
