<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Listener;

use FlatRate\FlarumMediaPrivacy\Access\PrivateObjectPolicy;
use Flarum\User\Exception\PermissionDeniedException;
use FoF\Upload\Events\File\WillBeDownloaded;
use Psr\Http\Message\ResponseInterface;

/**
 * Fail closed for private-shared files and private object ACLs.
 * Public upload methods are left on their existing delivery path.
 */
final class PrivateDownloadGuard
{
    public function handle(WillBeDownloaded $event): void
    {
        $file = $event->file;
        $method = isset($file->upload_method) ? (string) $file->upload_method : null;
        $acl = isset($file->acl) ? (string) $file->acl : null;
        if (!PrivateObjectPolicy::requiresAuthorization($method, $acl)) {
            return;
        }

        $actor = $event->download?->actor ?? null;
        $suspended = false;
        if ($actor && method_exists($actor, 'isSuspended') && $actor->isSuspended()) {
            $suspended = true;
        }
        if ($actor && !empty($actor->suspended_until)) {
            $until = $actor->suspended_until;
            $ts = $until instanceof \DateTimeInterface ? $until->getTimestamp() : strtotime((string) $until);
            if ($ts && $ts > time()) {
                $suspended = true;
            }
        }

        $participants = [];
        if (isset($file->participant_ids) && is_array($file->participant_ids)) {
            $participants = $file->participant_ids;
        }

        $decision = PrivateObjectPolicy::decision(
            [
                'id' => $actor && !empty($actor->id) ? (int) $actor->id : null,
                'suspended' => $suspended,
            ],
            [
                'ownerId' => isset($file->actor_id) ? (int) $file->actor_id : null,
                'method' => $method,
                'acl' => $acl,
                'key' => (string) ($file->path ?? ''),
                'deleted' => !empty($file->hidden) || !empty($file->deleted_at),
                'revoked' => !empty($file->revoked),
                'participantIds' => $participants,
            ]
        );

        if ($decision !== 'allow') {
            throw new PermissionDeniedException();
        }

        if ($event->response instanceof ResponseInterface) {
            $event->response = $event->response->withHeader('Cache-Control', 'private, no-store');
        }
    }
}
