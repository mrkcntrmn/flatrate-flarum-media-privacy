<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Listener;

use FlatRate\FlarumMediaPrivacy\Access\PrivateObjectPolicy;
use FoF\Upload\Events\File\WillBeSaved;

/**
 * FoF Upload 2.0.2 nulls actor_id when a file is shared.
 * Private-shared files are shared and hidden. Keep the uploader in the
 * existing actor_id column so a later download can require that owner.
 */
final class PersistPrivateSharedOwner
{
    public function handle(WillBeSaved $event): void
    {
        $file = $event->file;
        if (($file->upload_method ?? null) !== PrivateObjectPolicy::PRIVATE_METHOD) {
            return;
        }
        if (!empty($file->actor_id)) {
            return;
        }
        if ($event->actor->isGuest() || empty($event->actor->id)) {
            return;
        }

        $file->actor_id = (int) $event->actor->id;
    }
}
