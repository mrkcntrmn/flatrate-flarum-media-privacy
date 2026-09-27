<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Provider;

use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripper;
use FlatRate\FlarumMediaPrivacy\Listener\StripUploadImageMetadata;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class MetadataPrivacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImageMetadataStripper::class);

        $this->app->bind(StripUploadImageMetadata::class, function ($app) {
            $logger = $app->bound(LoggerInterface::class)
                ? $app->make(LoggerInterface::class)
                : ($app->bound('log') ? $app->make('log') : null);

            return new StripUploadImageMetadata(
                $app->make(ImageMetadataStripper::class),
                $logger
            );
        });
    }
}
