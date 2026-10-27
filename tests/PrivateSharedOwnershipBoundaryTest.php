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
use FoF\Upload\Events\File\WillBeSaved;
use FoF\Upload\File;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PrivateSharedOwnershipBoundaryTest extends TestCase
{
    public function testOwnerComesFromTheWillBeSavedActorBeforeSave(): void
    {
        $handler = (string) file_get_contents(dirname(__DIR__).'/vendor/fof/upload/src/Commands/UploadHandler.php');
        $api = (string) file_get_contents(dirname(__DIR__).'/vendor/fof/upload/src/Api/Handlers/UploadHandler.php');
        $listener = (string) file_get_contents(dirname(__DIR__).'/src/Listener/PersistPrivateSharedOwner.php');

        $willBeSaved = strpos($handler, 'new Events\File\WillBeSaved($command->actor, $file, $upload, $mime)');
        $save = strpos($handler, '$file->save()');
        $this->assertNotFalse($willBeSaved);
        $this->assertNotFalse($save);
        $this->assertLessThan($save, $willBeSaved);
        $this->assertStringContainsString('RequestUtil::getActor($request)', $api);
        $this->assertStringContainsString('new Upload($files, $actor, $hideFromMediaManager, $shared)', $api);
        $this->assertStringNotContainsString("getOption('actor", $api);
        $this->assertStringNotContainsString('->save(', $listener);

        $file = $this->privateSharedRow(null);
        $file->requested_actor_id = 9;
        $this->stamp($file, $this->member(4));

        $this->assertSame(4, (int) $file->actor_id);
        $this->assertSame(9, $file->requested_actor_id);
    }

    public function testOnlyEmptyPrivateSharedRowsReceiveAnOwner(): void
    {
        $local = $this->privateSharedRow(null);
        $local->upload_method = 'local';
        $local->shared = true;
        $local->hidden = false;
        $this->stamp($local, $this->member(4));
        $this->assertNull($local->actor_id);

        $publicShared = $this->privateSharedRow(null);
        $publicShared->upload_method = 'aws-s3';
        $publicShared->shared = true;
        $publicShared->hidden = false;
        $this->stamp($publicShared, $this->member(4));
        $this->assertNull($publicShared->actor_id);

        $alreadyOwned = $this->privateSharedRow(4);
        $this->stamp($alreadyOwned, $this->member(9));
        $this->assertSame(4, (int) $alreadyOwned->actor_id);

        $guestUpload = $this->privateSharedRow(null);
        $this->stamp($guestUpload, new Guest());
        $this->assertNull($guestUpload->actor_id);
    }

    public function testHistoricalNullOwnerStaysDeniedToWhoeverKnowsTheUuid(): void
    {
        $spy = new SpyPrivateReader();
        $downloader = new PrivateSharedAuthorizingDownloader($spy);
        $file = $this->privateSharedRow(null);
        $file->path = '2026-10-22/1690000000-1-aabbccddeeff00112233445566778899.jpg';
        $file->uuid = 'known-uuid';

        try {
            $downloader->download($file, new DownloadCommand('known-uuid', $this->member(4)));
            $this->fail('a null owner was readable');
        } catch (PermissionDeniedException $e) {
            $this->assertSame(0, $spy->calls);
        }
    }

    public function testStampedOwnerDoesNotEnterThePersonalLibraryOrSharedDeletionPredicate(): void
    {
        $filePhp = (string) file_get_contents(dirname(__DIR__).'/vendor/fof/upload/src/File.php');
        $policy = (string) file_get_contents(dirname(__DIR__).'/vendor/fof/upload/src/Access/FilePolicy.php');
        $repository = (string) file_get_contents(dirname(__DIR__).'/vendor/fof/upload/src/Repositories/FileRepository.php');

        $this->assertStringContainsString("->where('actor_id', \$userId)", $filePhp);
        $this->assertStringContainsString("->where('hidden', false)", $filePhp);
        $this->assertStringContainsString("->where('shared', false)", $filePhp);
        $this->assertStringContainsString("->where('shared', true)", $filePhp);

        $delete = strpos($policy, 'function delete');
        $sharedBranch = strpos($policy, 'if ($file->shared)', $delete);
        $owns = strpos($policy, '$this->owns(', $delete);
        $this->assertNotFalse($delete);
        $this->assertNotFalse($sharedBranch);
        $this->assertLessThan($owns, $sharedBranch);

        $cleanup = strpos($repository, 'function cleanUp');
        $this->assertNotFalse($cleanup);
        $this->assertNotFalse(strpos($repository, "->where('shared', false)", $cleanup));

        $row = [
            'actor_id' => 4,
            'hidden' => true,
            'shared' => true,
            'upload_method' => 'private-shared',
        ];
        $this->assertFalse($this->inPersonalLibrary($row));
        $this->assertTrue($this->inSharedListing($row));
        $this->assertFalse($this->eligibleForUnusedCleanup($row));

        $ordinary = [
            'actor_id' => null,
            'hidden' => false,
            'shared' => true,
            'upload_method' => 'aws-s3',
        ];
        $this->assertFalse($this->inPersonalLibrary($ordinary));
        $this->assertTrue($this->inSharedListing($ordinary));
        $this->assertFalse($this->eligibleForUnusedCleanup($ordinary));
    }

    private function stamp(File $file, User $actor): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fr-upload');
        $uploaded = new UploadedFile($path, 'a.jpg', null, null, true);
        (new PersistPrivateSharedOwner())->handle(new WillBeSaved($actor, $file, $uploaded, 'image/jpeg'));
        @unlink($path);
    }

    private function privateSharedRow(?int $ownerId): File
    {
        $file = new File();
        $file->upload_method = 'private-shared';
        $file->shared = true;
        $file->hidden = true;
        $file->actor_id = $ownerId;

        return $file;
    }

    private function member(int $id): User
    {
        $user = new User();
        $user->id = $id;

        return $user;
    }

    /**
     * @param array{actor_id:?int,hidden:bool,shared:bool,upload_method:string} $file
     */
    private function inPersonalLibrary(array $file): bool
    {
        return $file['actor_id'] !== null && $file['hidden'] === false && $file['shared'] === false;
    }

    /**
     * @param array{actor_id:?int,hidden:bool,shared:bool,upload_method:string} $file
     */
    private function inSharedListing(array $file): bool
    {
        return $file['shared'] === true;
    }

    /**
     * @param array{actor_id:?int,hidden:bool,shared:bool,upload_method:string} $file
     */
    private function eligibleForUnusedCleanup(array $file): bool
    {
        return $file['shared'] === false;
    }
}
