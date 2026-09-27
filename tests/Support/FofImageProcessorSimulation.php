<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests\Support;

use FoF\Upload\Events\File\WillBeUploaded;
use Intervention\Image\ImageManager;

/**
 * Minimal stand-in for FoF Upload 1.9 {@see \FoF\Upload\Processors\ImageProcessor} JPEG/PNG handling.
 */
final class FofImageProcessorSimulation
{
    public function handle(WillBeUploaded $event): void
    {
        if (!in_array($event->mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
            return;
        }

        if ($event->mime === 'image/gif') {
            return;
        }

        $image = (new ImageManager())->make($event->uploadedFile->getRealPath());
        $image->orientate();

        @file_put_contents(
            $event->uploadedFile->getRealPath(),
            $image->encode($event->mime)
        );
    }
}
