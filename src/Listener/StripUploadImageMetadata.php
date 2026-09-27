<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Listener;

use Flarum\Foundation\ValidationException;
use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripFailedException;
use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripper;
use FlatRate\FlarumMediaPrivacy\Image\ImageUploadMimeInspector;
use FoF\Upload\Events\File\WillBeUploaded;
use Psr\Log\LoggerInterface;

/**
 * Strip identifying image metadata from FoF Upload temp files before storage adapters run.
 *
 * FoF Upload 1.9 sets {@see WillBeUploaded::$mime} from {@see FileRepository::determineMime()},
 * which content-sniffs via php-mime-detector + fileinfo (not the client Content-Type).
 */
final class StripUploadImageMetadata
{
    public function __construct(
        private ImageMetadataStripper $stripper,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function handle(WillBeUploaded $event): void
    {
        $path = $event->uploadedFile->getRealPath();
        if ($path === false) {
            throw new ValidationException([
                'upload' => 'Upload could not be sanitized for privacy',
            ]);
        }

        if (!ImageUploadMimeInspector::isImageUpload($event->mime, $path)) {
            return;
        }

        $stripMime = ImageUploadMimeInspector::resolveStripMime($event->mime, $path);

        if (!$this->stripper->supportsMime($stripMime)) {
            throw new ValidationException([
                'upload' => 'Upload could not be sanitized for privacy',
            ]);
        }

        try {
            $this->stripper->strip($path, $stripMime);
        } catch (ImageMetadataStripFailedException $e) {
            $this->logFailure($e);
            throw new ValidationException([
                'upload' => 'Upload could not be sanitized for privacy',
            ]);
        } catch (\Throwable $e) {
            $this->logFailure($e);
            throw new ValidationException([
                'upload' => 'Upload could not be sanitized for privacy',
            ]);
        }

        $size = filesize($path);
        if ($size !== false) {
            $event->file->size = $size;
        }
    }

    private function logFailure(\Throwable $e): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->warning('FoF Upload image metadata strip failed', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
