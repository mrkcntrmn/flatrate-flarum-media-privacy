<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests;

use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripper;
use FlatRate\FlarumMediaPrivacy\Listener\StripUploadImageMetadata;
use FlatRate\FlarumMediaPrivacy\Tests\Support\ExifFixtureBuilder;
use FlatRate\FlarumMediaPrivacy\Tests\Support\FofImageProcessorSimulation;
use Flarum\User\User;
use FoF\Upload\Events\File\WillBeUploaded;
use FoF\Upload\File;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WillBeUploadedListenerOrderTest extends TestCase
{
    public function test_metadata_strip_after_fof_processor_leaves_no_identifying_metadata(): void
    {
        $this->dispatchWithListenerOrder([
            FofImageProcessorSimulation::class,
            StripUploadImageMetadata::class,
        ]);
    }

    public function test_metadata_strip_before_fof_processor_leaves_no_identifying_metadata(): void
    {
        $this->dispatchWithListenerOrder([
            StripUploadImageMetadata::class,
            FofImageProcessorSimulation::class,
        ]);
    }

    /**
     * @param array<int, class-string> $listenerClasses
     */
    private function dispatchWithListenerOrder(array $listenerClasses): void
    {
        $path = sys_get_temp_dir().'/privacy-order-'.bin2hex(random_bytes(4)).'.jpg';
        ExifFixtureBuilder::createJpegWithMetadata($path, 140, 90);

        $upload = new UploadedFile($path, 'order.jpg', 'image/jpeg', null, true);
        $file = (new File())->forceFill([
            'uuid' => '00000000-0000-4000-8000-000000000010',
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

        $dispatcher = new Dispatcher();
        foreach ($listenerClasses as $listenerClass) {
            $listener = $listenerClass === StripUploadImageMetadata::class
                ? new StripUploadImageMetadata(new ImageMetadataStripper())
                : new FofImageProcessorSimulation();

            $dispatcher->listen(WillBeUploaded::class, [$listener, 'handle']);
        }

        $dispatcher->dispatch($event);

        ExifFixtureBuilder::assertNoIdentifyingMetadata($path);
    }
}
