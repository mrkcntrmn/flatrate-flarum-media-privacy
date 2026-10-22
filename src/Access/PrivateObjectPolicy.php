<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Access;

/**
 * Server-side classification for FoF Upload objects.
 *
 * Public local files keep their storage URL. private-shared objects and
 * objects stored with a private ACL must not advertise a bucket or CDN URL.
 * Opaque basenames are not access control.
 */
final class PrivateObjectPolicy
{
    public const PRIVATE_METHOD = 'private-shared';

    public static function isPrivateUploadMethod(?string $method): bool
    {
        return $method === self::PRIVATE_METHOD;
    }

    public static function isPrivateObjectAcl(?string $acl): bool
    {
        if ($acl === null || $acl === '') {
            return false;
        }

        $normalized = strtolower(str_replace('_', '-', $acl));

        return $normalized === 'private';
    }

    public static function requiresAuthorization(?string $method, ?string $acl): bool
    {
        return self::isPrivateUploadMethod($method) || self::isPrivateObjectAcl($acl);
    }

    /**
     * @return string|null Storage URL safe to publish. Null means the client
     *                     must use the application download route.
     */
    public static function advertisedStorageUrl(?string $method, ?string $acl, ?string $storageUrl): ?string
    {
        if (self::requiresAuthorization($method, $acl)) {
            return null;
        }

        return $storageUrl;
    }

    public static function objectKeyIsSafe(string $key): bool
    {
        if ($key === '' || strlen($key) > 255) {
            return false;
        }
        if (str_contains($key, "\0") || str_contains($key, '..') || str_contains($key, '/') || str_contains($key, '\\') || str_contains($key, '://')) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._-]{1,255}$/', $key) === 1;
    }

    /**
     * @param array{id:?int,suspended?:bool} $actor
     * @param array{ownerId:?int,method:?string,acl:?string,key:string,deleted?:bool,revoked?:bool,participantIds?:list<int>} $file
     */
    public static function decision(array $actor, array $file): string
    {
        if (!self::objectKeyIsSafe((string) ($file['key'] ?? ''))) {
            return 'traversal';
        }

        if (!self::requiresAuthorization($file['method'] ?? null, $file['acl'] ?? null)) {
            return 'public';
        }

        if (!empty($file['deleted']) || !empty($file['revoked'])) {
            return 'revoked';
        }

        $actorId = $actor['id'] ?? null;
        if ($actorId === null || $actorId === 0) {
            return 'guest';
        }
        if (!empty($actor['suspended'])) {
            return 'suspended';
        }

        $ownerId = isset($file['ownerId']) ? (int) $file['ownerId'] : 0;
        $participants = array_map('intval', $file['participantIds'] ?? []);
        $actorId = (int) $actorId;
        if ($actorId === $ownerId || in_array($actorId, $participants, true)) {
            return 'allow';
        }

        return 'cross_user';
    }

    /**
     * @return array<string, string>
     */
    public static function privateResponseHeaders(string $mediaType, string $downloadName): array
    {
        $safeName = str_replace(['"', "\r", "\n", '\\'], '', $downloadName);
        if ($safeName === '' || !self::objectKeyIsSafe($safeName)) {
            $safeName = 'download';
        }

        return [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => $mediaType,
            'Content-Disposition' => 'attachment; filename="'.$safeName.'"',
        ];
    }
}
