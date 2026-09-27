<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Provider;

use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripper;
use Illuminate\Support\ServiceProvider;

final class MetadataPrivacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImageMetadataStripper::class);
    }
}
