<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests;

use FlatRate\FlarumMediaPrivacy\Access\PrivateObjectPolicy;
use PHPUnit\Framework\TestCase;

final class PrivateObjectPolicyTest extends TestCase
{
    public function testPublicLocalUrlStaysAdvertised(): void
    {
        $url = 'https://forum.example/assets/files/abcdef.jpg';
        $this->assertSame($url, PrivateObjectPolicy::advertisedStorageUrl('local', null, $url));
        $this->assertSame('public', PrivateObjectPolicy::decision(
            ['id' => null],
            ['ownerId' => 4, 'method' => 'local', 'acl' => null, 'key' => 'abcdef.jpg']
        ));
    }

    public function testPrivateSharedAndPrivateAclSuppressStorageUrls(): void
    {
        $url = 'https://bucket.example/abcdef.jpg';
        $this->assertNull(PrivateObjectPolicy::advertisedStorageUrl('private-shared', null, $url));
        $this->assertNull(PrivateObjectPolicy::advertisedStorageUrl('aws-s3', 'private', $url));
        $this->assertSame($url, PrivateObjectPolicy::advertisedStorageUrl('aws-s3', 'public-read', $url));
    }

    public function testPrivateRetrievalDeniesGuestCrossUserSuspendedAndRevoked(): void
    {
        $file = [
            'ownerId' => 4,
            'method' => 'private-shared',
            'acl' => null,
            'key' => 'aabbccddeeff00112233445566778899.jpg',
            'participantIds' => [9],
        ];

        $this->assertSame('guest', PrivateObjectPolicy::decision(['id' => null], $file));
        $this->assertSame('cross_user', PrivateObjectPolicy::decision(['id' => 5], $file));
        $this->assertSame('suspended', PrivateObjectPolicy::decision(['id' => 4, 'suspended' => true], $file));
        $this->assertSame('revoked', PrivateObjectPolicy::decision(['id' => 4], $file + ['revoked' => true]));
        $this->assertSame('allow', PrivateObjectPolicy::decision(['id' => 4], $file));
        $this->assertSame('allow', PrivateObjectPolicy::decision(['id' => 9], $file));
    }

    public function testTraversalAndHeaderSafety(): void
    {
        $this->assertSame('traversal', PrivateObjectPolicy::decision(
            ['id' => 4],
            ['ownerId' => 4, 'method' => 'private-shared', 'acl' => null, 'key' => '../secret.jpg']
        ));
        $this->assertFalse(PrivateObjectPolicy::objectKeyIsSafe('a/b.jpg'));
        $this->assertFalse(PrivateObjectPolicy::objectKeyIsSafe("bad\0.jpg"));

        $headers = PrivateObjectPolicy::privateResponseHeaders('image/jpeg', 'photo.jpg');
        $this->assertSame('private, no-store', $headers['Cache-Control']);
        $this->assertSame('image/jpeg', $headers['Content-Type']);
        $this->assertStringContainsString('filename="photo.jpg"', $headers['Content-Disposition']);

        $unsafe = PrivateObjectPolicy::privateResponseHeaders('image/jpeg', "../etc/passwd\r\n");
        $this->assertSame('attachment; filename="download"', $unsafe['Content-Disposition']);
    }
}
