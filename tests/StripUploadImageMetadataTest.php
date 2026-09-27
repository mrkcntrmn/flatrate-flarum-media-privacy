<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests;

use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripper;
use FlatRate\FlarumMediaPrivacy\Listener\StripUploadImageMetadata;
use FlatRate\FlarumMediaPrivacy\Tests\Support\ExifFixtureBuilder;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use FoF\Upload\Events\File\WillBeUploaded;
use FoF\Upload\File;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class StripUploadImageMetadataTest extends TestCase
{
    public function test_will_be_uploaded_listener_strips_fixture_and_updates_size(): void
    {
        $path = $this->tempPath('listener.jpg');
        ExifFixtureBuilder::createJpegWithMetadata($path, 140, 100);

        $upload = new UploadedFile($path, 'listener.jpg', 'image/jpeg', null, true);
        $file = (new File())->forceFill([
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'base_name' => bin2hex(random_bytes(16)).'.jpg',
            'size' => filesize($path),
            'type' => 'image/jpeg',
        ]);

        $event = new WillBeUploaded(
            $this->createMock(User::class),
            $file,
            $upload,
            'image/jpeg'
        );

        (new StripUploadImageMetadata(new ImageMetadataStripper()))->handle($event);

        ExifFixtureBuilder::assertNoIdentifyingMetadata($path);
        $this->assertSame(filesize($path), $event->file->size);
    }

    public function test_listener_fails_closed_when_stripper_rejects_upload(): void
    {
        $path = $this->tempPath('reject.jpg');
        file_put_contents($path, 'invalid');

        $upload = new UploadedFile($path, 'reject.jpg', 'image/jpeg', null, true);
        $file = (new File())->forceFill([
            'uuid' => '00000000-0000-4000-8000-000000000002',
            'base_name' => bin2hex(random_bytes(16)).'.jpg',
            'size' => 7,
            'type' => 'image/jpeg',
        ]);

        $event = new WillBeUploaded(
            $this->createMock(User::class),
            $file,
            $upload,
            'image/jpeg'
        );

        try {
            (new StripUploadImageMetadata(new ImageMetadataStripper()))->handle($event);
            $this->fail('Expected ValidationException when metadata strip fails');
        } catch (ValidationException $e) {
            $this->assertSame(['upload' => 'Upload could not be sanitized for privacy'], $e->getAttributes());
        }
    }

    private function tempPath(string $basename): string
    {
        $target = sys_get_temp_dir().'/privacy-listener-'.bin2hex(random_bytes(4)).'-'.$basename;
        $this->assertNotFalse(touch($target));

        return $target;
    }
}
