<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests;

use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripper;
use FlatRate\FlarumMediaPrivacy\Tests\Support\QuadrantOrientationFixtureBuilder;
use PHPUnit\Framework\TestCase;

final class OrientationQuadrantTest extends TestCase
{
    private ImageMetadataStripper $stripper;

    protected function setUp(): void
    {
        $this->stripper = new ImageMetadataStripper();
    }

    /**
     * @dataProvider orientationProvider
     */
    public function test_jpeg_orientation_normalizes_to_absolute_quadrant_layout(int $orientation): void
    {
        $path = sys_get_temp_dir().'/privacy-orient-'.bin2hex(random_bytes(4)).'.jpg';
        QuadrantOrientationFixtureBuilder::createTaggedJpeg($path, $orientation);

        $this->stripper->strip($path, 'image/jpeg');

        QuadrantOrientationFixtureBuilder::assertNormalizedUprightLayout($path);
        QuadrantOrientationFixtureBuilder::assertOrientationTagAbsent($path);

        @unlink($path);
    }

    public function orientationProvider(): array
    {
        return [
            'orientation 1' => [1],
            'orientation 2 mirrored horizontal' => [2],
            'orientation 3 rotate 180' => [3],
            'orientation 4 mirrored vertical' => [4],
            'orientation 5 transpose' => [5],
            'orientation 6 rotate 90 cw' => [6],
            'orientation 7 transverse' => [7],
            'orientation 8 rotate 90 ccw' => [8],
        ];
    }
}
