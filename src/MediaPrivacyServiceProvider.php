<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy;

use FlatRate\FlarumMediaPrivacy\Download\PrivateSharedAuthorizingDownloader;
use Flarum\Foundation\AbstractServiceProvider;
use FoF\Upload\Commands\DownloadHandler;

/**
 * Register the private-shared authorizer ahead of FoF's byte reader.
 */
final class MediaPrivacyServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        if (!method_exists(DownloadHandler::class, 'prependDownloader')) {
            return;
        }

        DownloadHandler::prependDownloader(
            $this->container->make(PrivateSharedAuthorizingDownloader::class)
        );
    }
}
