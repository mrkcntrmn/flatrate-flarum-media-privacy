<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

use Flarum\Extend;
use FlatRate\FlarumMediaPrivacy\Listener\OpaqueUploadBasename;
use FlatRate\FlarumMediaPrivacy\Listener\StripUploadImageMetadata;
use FlatRate\FlarumMediaPrivacy\Provider\MetadataPrivacyServiceProvider;
use FoF\Upload\Events\File\IsSlugged;
use FoF\Upload\Events\File\WillBeUploaded;

if (!class_exists(IsSlugged::class) || !class_exists(WillBeUploaded::class)) {
    return [];
}

return [
    (new Extend\Event())
        ->listen(IsSlugged::class, OpaqueUploadBasename::class)
        ->listen(WillBeUploaded::class, StripUploadImageMetadata::class),

    (new Extend\ServiceProvider())
        ->register(MetadataPrivacyServiceProvider::class),
];
