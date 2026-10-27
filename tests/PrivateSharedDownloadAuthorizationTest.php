<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests;

use FlatRate\FlarumMediaPrivacy\Download\PrivateSharedAuthorizingDownloader;
use FlatRate\FlarumMediaPrivacy\Listener\PersistPrivateSharedOwner;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\Guest;
use Flarum\User\User;
use FoF\Upload\Commands\Download as DownloadCommand;
use FoF\Upload\Downloader\DefaultDownloader;
use FoF\Upload\Events\File\WillBeDownloaded;
use FoF\Upload\File;
use GuzzleHttp\Client;
use Laminas\Diactoros\Response\TextResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class PrivateSharedDownloadAuthorizationTest extends TestCase
{
    public function testLoggingEnabledOwnerIsAllowedWithoutReadingBeforeAuthorization(): void
    {
        $spy = new SpyPrivateReader();
        $downloader = new PrivateSharedAuthorizingDownloader($spy);
        $file = $this->privateFile(4);
        $response = $downloader->download($file, new DownloadCommand('uuid-1', $this->member(4)));

        $this->assertSame(1, $spy->calls);
        $this->assertSame('PRIVATE-BYTES', (string) $response->getBody());
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testLoggingDisabledOwnerIsAllowedFromTheCommandActor(): void
    {
        $spy = new SpyPrivateReader();
        $downloader = new PrivateSharedAuthorizingDownloader($spy);
        $file = $this->privateFile(4);
        $response = $downloader->download($file, new DownloadCommand('uuid-1', $this->member(4)));
        $logged = new WillBeDownloaded($file, $response, null);

        $this->assertNull($logged->download);
        $this->assertSame(1, $spy->calls);
        $this->assertSame('PRIVATE-BYTES', (string) $response->getBody());
    }

    public function testGuestIsDeniedWhenLoggingIsEnabledOrDisabled(): void
    {
        foreach ([true, false] as $loggingEnabled) {
            $spy = new SpyPrivateReader();
            $downloader = new PrivateSharedAuthorizingDownloader($spy);
            $file = $this->privateFile(4);
            $log = $loggingEnabled ? new \FoF\Upload\Download() : null;
            $response = new TextResponse('should-not-be-returned');
            $event = new WillBeDownloaded($file, $response, $log);

            try {
                $downloader->download($file, new DownloadCommand('uuid-1', new Guest()));
                $this->fail('guest download returned a response');
            } catch (PermissionDeniedException $e) {
                $this->assertSame(0, $spy->calls);
                $this->assertNotSame('PRIVATE-BYTES', (string) $event->response->getBody());
            }
        }
    }

    public function testCrossUserIsDeniedEvenIfAParticipantAttributeIsPresent(): void
    {
        $spy = new SpyPrivateReader();
        $downloader = new PrivateSharedAuthorizingDownloader($spy);
        $file = $this->privateFile(4);
        $file->participant_ids = [9];
        $file->acl = 'private';

        $this->expectException(PermissionDeniedException::class);
        try {
            $downloader->download($file, new DownloadCommand('uuid-1', $this->member(9)));
        } finally {
            $this->assertSame(0, $spy->calls);
        }
    }

    public function testRevokedParticipantIsDenied(): void
    {
        $spy = new SpyPrivateReader();
        $downloader = new PrivateSharedAuthorizingDownloader($spy);
        $file = $this->privateFile(4);
        $file->hidden = true;
        $file->participant_ids = [9];

        $this->expectException(PermissionDeniedException::class);
        try {
            $downloader->download($file, new DownloadCommand('uuid-1', $this->member(9)));
        } finally {
            $this->assertSame(0, $spy->calls);
        }
    }

    public function testSuspendedOwnerIsDenied(): void
    {
        $spy = new SpyPrivateReader();
        $downloader = new PrivateSharedAuthorizingDownloader($spy);
        $actor = new class extends User {
            public function isSuspended(): bool
            {
                return true;
            }
        };
        $actor->id = 4;

        $this->expectException(PermissionDeniedException::class);
        try {
            $downloader->download($this->privateFile(4), new DownloadCommand('uuid-1', $actor));
        } finally {
            $this->assertSame(0, $spy->calls);
        }
    }

    public function testFoFPrivateSharedShapeAllowsTheStoredOwner(): void
    {
        $spy = new SpyPrivateReader();
        $downloader = new PrivateSharedAuthorizingDownloader($spy);
        $file = $this->privateFile(4);
        $file->shared = true;
        $file->hidden = true;

        $response = $downloader->download($file, new DownloadCommand('uuid-1', $this->member(4)));

        $this->assertSame(1, $spy->calls);
        $this->assertSame('PRIVATE-BYTES', (string) $response->getBody());
    }

    public function testUnstampedPrivateSharedFileIsDenied(): void
    {
        $spy = new SpyPrivateReader();
        $downloader = new PrivateSharedAuthorizingDownloader($spy);
        $file = $this->privateFile(4);
        $file->actor_id = null;
        $file->shared = true;
        $file->hidden = true;

        $this->expectException(PermissionDeniedException::class);
        try {
            $downloader->download($file, new DownloadCommand('uuid-1', $this->member(4)));
        } finally {
            $this->assertSame(0, $spy->calls);
        }
    }

    public function testWillBeSavedStoresTheUploaderOnActorId(): void
    {
        $file = new File();
        $file->upload_method = 'private-shared';
        $file->shared = true;
        $file->hidden = true;
        $file->actor_id = null;
        $path = tempnam(sys_get_temp_dir(), 'fr-upload');
        $uploaded = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'a.jpg', null, null, true);

        (new PersistPrivateSharedOwner())->handle(
            new \FoF\Upload\Events\File\WillBeSaved($this->member(4), $file, $uploaded, 'image/jpeg')
        );

        $this->assertSame(4, (int) $file->actor_id);
        @unlink($path);
    }

    public function testInvalidObjectKeyIsDeniedBeforeRead(): void
    {
        $spy = new SpyPrivateReader();
        $downloader = new PrivateSharedAuthorizingDownloader($spy);
        $file = $this->privateFile(4);
        $file->path = '2026-10-22/../../secret.jpg';

        $this->expectException(PermissionDeniedException::class);
        try {
            $downloader->download($file, new DownloadCommand('uuid-1', $this->member(4)));
        } finally {
            $this->assertSame(0, $spy->calls);
        }
    }

    public function testDatedPrivatePathIsReadableByItsOwner(): void
    {
        $spy = new SpyPrivateReader();
        $downloader = new PrivateSharedAuthorizingDownloader($spy);
        $file = $this->privateFile(4);
        $file->path = '2026-10-22/1690000000-1-aabbccddeeff00112233445566778899.jpg';

        $response = $downloader->download($file, new DownloadCommand('uuid-1', $this->member(4)));

        $this->assertSame(1, $spy->calls);
        $this->assertSame('PRIVATE-BYTES', (string) $response->getBody());
    }

    public function testAuthorizationSourceDoesNotUseTheLoggingRecordOrUnstoredFields(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/src/Download/PrivateSharedAuthorizingDownloader.php');
        $extend = (string) file_get_contents(dirname(__DIR__).'/extend.php');

        $this->assertStringNotContainsString('download?->actor', $source);
        $this->assertStringNotContainsString('$file->participant_ids', $source);
        $this->assertStringNotContainsString('$file->acl', $source);
        $this->assertStringNotContainsString('use FoF\\Upload\\Events\\File\\WillBeDownloaded', $source);
        $this->assertStringNotContainsString('PrivateDownloadGuard', $extend);
        $this->assertStringContainsString('prependDownloader', $extend);
        $this->assertStringContainsString('MediaPrivacyServiceProvider', $extend);
        $this->assertStringContainsString('PersistPrivateSharedOwner', $extend);
    }

    private function privateFile(int $ownerId): File
    {
        $file = new File();
        $file->upload_method = 'private-shared';
        $file->actor_id = $ownerId;
        $file->hidden = false;
        $file->path = 'aabbccddeeff00112233445566778899.jpg';
        $file->base_name = 'aabbccddeeff00112233445566778899.jpg';
        $file->uuid = 'uuid-1';

        return $file;
    }

    private function member(int $id): User
    {
        $user = new User();
        $user->id = $id;

        return $user;
    }
}

final class SpyPrivateReader extends DefaultDownloader
{
    public int $calls = 0;

    public function __construct()
    {
        parent::__construct(new Client());
    }

    public function download(File $file, ?DownloadCommand $command = null): ResponseInterface
    {
        $this->calls++;

        return new TextResponse('PRIVATE-BYTES');
    }
}
