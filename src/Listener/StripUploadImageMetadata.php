<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Listener;

use Flarum\Foundation\ValidationException;
use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripFailedException;
use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripper;
use FoF\Upload\Events\File\WillBeUploaded;

/**
 * Strip identifying image metadata from FoF Upload temp files before storage adapters run.
 */
final class StripUploadImageMetadata
{
    public function __construct(
        private ImageMetadataStripper $stripper
    ) {
    }

    public function handle(WillBeUploaded $event): void
    {
        if (!$this->stripper->supportsMime($event->mime)) {
            return;
        }

        $path = $event->uploadedFile->getRealPath();
        if ($path === false) {
            throw new ValidationException([
                'upload' => 'Upload could not be sanitized for privacy',
            ]);
        }

        try {
            $this->stripper->strip($path, $event->mime);
        } catch (ImageMetadataStripFailedException) {
            throw new ValidationException([
                'upload' => 'Upload could not be sanitized for privacy',
            ]);
        }

        $size = filesize($path);
        if ($size !== false) {
            $event->file->size = $size;
        }
    }
}
