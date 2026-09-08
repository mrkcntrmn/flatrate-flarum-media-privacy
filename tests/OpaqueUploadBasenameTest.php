<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests;

use FlatRate\FlarumMediaPrivacy\Listener\OpaqueUploadBasename;
use Flarum\User\User;
use FoF\Upload\Events\File\IsSlugged;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class OpaqueUploadBasenameTest extends TestCase
{
    private const IDENTIFYING_STEM = 'customer-smith-vin-123456789-test';
    private const IDENTIFYING_SLUG = 'customer-smith-vin-123456789-test.jpg';
    private const OPAQUE_WITH_EXT = '/^[0-9a-f]{32}\.[a-z0-9]{1,16}$/';
    private const OPAQUE_NO_EXT = '/^[0-9a-f]{32}$/';

    public function test_identifying_filename_fixture_is_replaced(): void
    {
        $event = $this->makeEvent(self::IDENTIFYING_SLUG);
        (new OpaqueUploadBasename())->handle($event);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', $event->slug);
        $this->assertNotSame(self::IDENTIFYING_SLUG, $event->slug);
        $this->assertStringNotContainsString(self::IDENTIFYING_STEM, $event->slug);
        $this->assertStringNotContainsString('customer', $event->slug);
        $this->assertStringNotContainsString('smith', $event->slug);
        $this->assertStringNotContainsString('vin', $event->slug);
        $this->assertStringEndsWith('.jpg', $event->slug);
    }

    public function test_repeated_invocations_yield_unique_opaque_basenames(): void
    {
        $listener = new OpaqueUploadBasename();
        $seen = [];

        for ($i = 0; $i < 100; $i++) {
            $event = $this->makeEvent(self::IDENTIFYING_SLUG);
            $listener->handle($event);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', $event->slug);
            $seen[] = $event->slug;
        }

        $this->assertCount(100, array_unique($seen));
    }

    /**
     * @dataProvider supportedImageExtensionProvider
     */
    public function test_supported_image_extensions_are_normalized(
        string $inputSlug,
        string $expectedExtension
    ): void {
        $event = $this->makeEvent($inputSlug);
        (new OpaqueUploadBasename())->handle($event);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{32}\.'.preg_quote($expectedExtension, '/').'$/',
            $event->slug
        );
        $this->assertStringNotContainsString(pathinfo($inputSlug, PATHINFO_FILENAME), $event->slug);
    }

    public function supportedImageExtensionProvider(): array
    {
        return [
            'jpg' => ['photo.jpg', 'jpg'],
            'jpeg lower' => ['photo.jpeg', 'jpeg'],
            'JPG upper' => ['photo.JPG', 'jpg'],
            'png' => ['photo.png', 'png'],
            'PNG upper' => ['photo.PNG', 'png'],
            'webp' => ['photo.webp', 'webp'],
            'WEBP upper' => ['photo.WEBP', 'webp'],
        ];
    }

    public function test_extensionless_slug_becomes_opaque_hex_only(): void
    {
        $event = $this->makeEvent('sensitive-filename');
        (new OpaqueUploadBasename())->handle($event);

        $this->assertMatchesRegularExpression(self::OPAQUE_NO_EXT, $event->slug);
        $this->assertStringNotContainsString('sensitive-filename', $event->slug);
        $this->assertStringNotContainsString('.', $event->slug);
    }

    public function test_invalid_extension_fails_closed_without_original_fallback(): void
    {
        $input = 'identifying-stem.bad_ext';
        $event = $this->makeEvent($input);

        try {
            (new OpaqueUploadBasename())->handle($event);
            $this->fail('Expected RuntimeException for unsafe extension');
        } catch (RuntimeException $e) {
            $this->assertSame('Unsafe FoF Upload file extension', $e->getMessage());
            $this->assertSame($input, $event->slug);
            $this->assertDoesNotMatchRegularExpression(self::OPAQUE_WITH_EXT, $event->slug);
        }
    }

    public function test_listener_source_does_not_use_identifying_context(): void
    {
        $source = file_get_contents(
            dirname(__DIR__).'/src/Listener/OpaqueUploadBasename.php'
        );
        $this->assertNotFalse($source);

        $forbidden = [
            'user->id',
            'user->username',
            'getClientOriginalName',
            'time()',
            'microtime',
            'discussion',
            'post_id',
            'actor_id',
            'getClientIp',
            'REMOTE_ADDR',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                "Listener must not reference identifying context: {$needle}"
            );
        }

        $this->assertStringContainsString('pathinfo($event->slug, PATHINFO_EXTENSION)', $source);
        $this->assertStringContainsString('bin2hex(random_bytes(16))', $source);
    }

    public function test_package_bootstrap_is_inert_without_fof_event_class(): void
    {
        $pkgRoot = dirname(__DIR__);
        $script = <<<'PHP'
<?php
declare(strict_types=1);
$pkgRoot = $argv[1];
spl_autoload_register(static function (string $class) use ($pkgRoot): void {
    if (str_starts_with($class, 'FoF\\')) {
        return;
    }
    if ($class === 'FlatRate\\FlarumMediaPrivacy\\Listener\\OpaqueUploadBasename') {
        require $pkgRoot.'/src/Listener/OpaqueUploadBasename.php';
    }
});
$result = require $pkgRoot.'/extend.php';
if ($result !== []) {
    fwrite(STDERR, "expected empty extender list when FoF IsSlugged is absent\n");
    exit(1);
}
echo "FOF_ABSENT_FATAL=false\n";
echo "PRIVACY_EXTENSION_INERT_WITHOUT_FOF=true\n";
PHP;

        $tmp = tempnam(sys_get_temp_dir(), 'privacy-bootstrap-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $script);

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($tmp).' '.escapeshellarg($pkgRoot);
        exec($cmd.' 2>&1', $output, $exitCode);
        @unlink($tmp);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('FOF_ABSENT_FATAL=false', $output);
        $this->assertContains('PRIVACY_EXTENSION_INERT_WITHOUT_FOF=true', $output);
    }

    public function test_no_identity_coupling_in_implementation(): void
    {
        $roots = [
            dirname(__DIR__).'/src',
            dirname(__DIR__).'/extend.php',
        ];
        $needles = [
            'Supabase',
            'community ticket',
            'HMAC',
            'wiki-supabase-oauth',
            'Job Breakdown',
            'TagLabel',
            'direct message',
            'private message',
        ];

        foreach ($roots as $root) {
            $files = is_dir($root)
                ? glob($root.'/**/*.php') ?: []
                : [$root];
            // Also walk recursively for src/
            if (is_dir($root)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root)
                );
                $files = [];
                foreach ($iterator as $file) {
                    if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                        $files[] = $file->getPathname();
                    }
                }
            }

            foreach ($files as $file) {
                $contents = file_get_contents($file);
                $this->assertNotFalse($contents);
                foreach ($needles as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $contents,
                        "Identity coupling found in {$file}: {$needle}"
                    );
                }
            }
        }
    }

    private function makeEvent(string $slug): IsSlugged
    {
        $tmp = tempnam(sys_get_temp_dir(), 'privacy-upload-');
        $this->assertNotFalse($tmp);
        // Harmless JPEG-like bytes for Symfony UploadedFile; content is unused by listener.
        file_put_contents($tmp, "\xff\xd8\xff\xd9");

        $upload = new UploadedFile(
            $tmp,
            self::IDENTIFYING_SLUG,
            'image/jpeg',
            null,
            true
        );

        /** @var User $user */
        $user = $this->createMock(User::class);

        return new IsSlugged($upload, $user, 'image/jpeg', $slug);
    }
}
