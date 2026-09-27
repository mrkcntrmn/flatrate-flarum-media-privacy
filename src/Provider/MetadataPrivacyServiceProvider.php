<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Provider;

use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripper;
use FlatRate\FlarumMediaPrivacy\Listener\StripUploadImageMetadata;
use FoF\Upload\Events\File\WillBeUploaded;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * Register the metadata stripper after FoF Upload's image processor on WillBeUploaded.
 */
final class MetadataPrivacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImageMetadataStripper::class);
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(
            WillBeUploaded::class,
            StripUploadImageMetadata::class,
            -100
        );
    }
}
