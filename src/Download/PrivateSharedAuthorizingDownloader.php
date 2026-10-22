<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Download;

use FlatRate\FlarumMediaPrivacy\Access\PrivateObjectPolicy;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use FoF\Upload\Commands\Download as DownloadCommand;
use FoF\Upload\Contracts\Downloader;
use FoF\Upload\Downloader\DefaultDownloader;
use FoF\Upload\File;
use Psr\Http\Message\ResponseInterface;

/**
 * Authorizes private-shared downloads from the download command.
 *
 * FoF Upload 2.0.2 reads the file before WillBeDownloaded. That event's
 * download value is the optional logging row, and it is null when
 * fof-upload.disableDownloadLogging is on. The command actor is the
 * identity authority. File::actor_id and File::hidden are the persisted
 * owner and revocation fields. An unstored participant list or object ACL is not consulted.
 */
final class PrivateSharedAuthorizingDownloader implements Downloader
{
    public function __construct(
        private DefaultDownloader $files
    ) {
    }

    public function forFile(File $file): bool
    {
        return ($file->upload_method ?? null) === PrivateObjectPolicy::PRIVATE_METHOD;
    }

    public function download(File $file, DownloadCommand $command): ResponseInterface
    {
        $this->assertCanRead($file, $command->actor);

        $response = $this->files->download($file, $command);

        return $response->withHeader('Cache-Control', 'private, no-store');
    }

    public function assertCanRead(File $file, User $actor): void
    {
        $path = (string) ($file->path ?? '');
        if (!PrivateObjectPolicy::storedPrivatePathIsSafe($path)) {
            throw new PermissionDeniedException();
        }

        if (!empty($file->hidden)) {
            throw new PermissionDeniedException();
        }

        $actorId = $this->actorId($actor);
        if ($actorId === null || $this->isSuspended($actor)) {
            throw new PermissionDeniedException();
        }

        $ownerId = (int) ($file->actor_id ?? 0);
        if ($ownerId === 0 || $actorId !== $ownerId) {
            throw new PermissionDeniedException();
        }
    }

    private function actorId(User $actor): ?int
    {
        if ($actor->isGuest() || empty($actor->id)) {
            return null;
        }

        return (int) $actor->id;
    }

    private function isSuspended(User $actor): bool
    {
        if (method_exists($actor, 'isSuspended') && $actor->isSuspended()) {
            return true;
        }

        $until = $actor->suspended_until ?? null;
        if ($until instanceof \DateTimeInterface) {
            return $until->getTimestamp() > time();
        }
        if (is_string($until) && $until !== '') {
            $ts = strtotime($until);

            return $ts !== false && $ts > time();
        }

        return false;
    }
}
