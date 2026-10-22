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
     * FoF stores private-shared objects as YYYY-MM-DD/<timestamp>-<micro>-<basename>.
     * A slash is the date prefix only. Traversal, extra segments, and absolute paths are rejected.
     */
    public static function storedPrivatePathIsSafe(string $path): bool
    {
        if ($path === '' || strlen($path) > 255) {
            return false;
        }
        if (str_contains($path, "\0") || str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, '://') || str_starts_with($path, '/')) {
            return false;
        }

        $parts = explode('/', $path);
        if (count($parts) > 2) {
            return false;
        }
        if (count($parts) === 2 && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $parts[0]) !== 1) {
            return false;
        }

        $name = $parts[count($parts) - 1];

        return preg_match('/^[A-Za-z0-9._-]{1,200}$/', $name) === 1;
    }

    /**
     * @param array{id:?int,suspended?:bool} $actor
     * @param array{ownerId:?int,method:?string,acl:?string,key:string,deleted?:bool,revoked?:bool} $file
     */
    public static function decision(array $actor, array $file): string
    {
        if (!self::storedPrivatePathIsSafe((string) ($file['key'] ?? ''))) {
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
        $actorId = (int) $actorId;
        // participantIds is not a FoF Upload file column. Non-owners stay denied.
        if ($ownerId !== 0 && $actorId === $ownerId) {
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
