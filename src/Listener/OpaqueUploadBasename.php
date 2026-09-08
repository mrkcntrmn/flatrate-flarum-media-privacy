<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Listener;

use FoF\Upload\Events\File\IsSlugged;
use RuntimeException;

/**
 * Replace FoF Upload's user-derived basename with a cryptographically random opaque name.
 *
 * Preserves only the safe extension already selected by FoF's filename/extension pipeline
 * (derived from the event slug, not from the client original name).
 */
final class OpaqueUploadBasename
{
    public function handle(IsSlugged $event): void
    {
        $extension = strtolower(
            pathinfo($event->slug, PATHINFO_EXTENSION)
        );

        if (
            $extension !== ''
            && !preg_match('/^[a-z0-9]{1,16}$/', $extension)
        ) {
            throw new RuntimeException(
                'Unsafe FoF Upload file extension'
            );
        }

        $basename = bin2hex(random_bytes(16));

        $event->slug = $extension === ''
            ? $basename
            : $basename.'.'.$extension;
    }
}
