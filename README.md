# flatrate/flarum-media-privacy

FlatRate.wiki Flarum extension that hardens FoF Upload media privacy: cryptographically
random opaque basenames and fail-closed stripping of identifying image metadata before
storage.

| Field | Value |
| --- | --- |
| Composer package | `flatrate/flarum-media-privacy` |
| Flarum extension ID | `flatrate-flarum-media-privacy` |
| Stable target | `1.0.0` |
| FoF integration | `fof/upload` **1.9.0** (`IsSlugged`, `WillBeUploaded`) |

## Responsibilities

### Opaque basenames (`IsSlugged`)

When FoF Upload dispatches `IsSlugged`, this extension:

1. derives the storage extension from `$event->slug` (FoF's already-selected extension);
2. fails closed if that extension is non-empty and outside `^[a-z0-9]{1,16}$`;
3. replaces the entire basename with `bin2hex(random_bytes(16))` (128 random bits);
4. preserves only that safe extension.

Accepted output grammar:

```text
^[0-9a-f]{32}(\.[a-z0-9]{1,16})?$
```

The opaque name does **not** encode original filename, user ID, username, email,
VIN, timestamp, discussion/post IDs, IP, session identifiers, or sequential
counters.

### Image metadata stripping (`WillBeUploaded`)

Before FoF Upload writes a file to its storage adapter (local disk, S3/R2, etc.),
this extension listens to `FoF\Upload\Events\File\WillBeUploaded`.

**MIME source:** FoF Upload 1.9 sets `$event->mime` from `FileRepository::determineMime()`,
which content-sniffs the temp file (`SoftCreatR\MimeDetector` + PHP `fileinfo`) and
rejects client/fileinfo mismatches. This extension re-sniffs the temp path with
`mime_content_type()` / `getimagesize()` / `exif_imagetype()` and treats any upload
as an image when either FoF's detected MIME or the bytes indicate `image/*`.

**Fail-closed image policy:** every image upload must be sanitized or rejected. JPEG
aliases (`image/jpg`, `image/pjpeg`, …) normalize to JPEG. Any other `image/*` type
that cannot be stripped safely (TIFF, AVIF, JPEG XL, SVG, ICO, HEIC/HEIF **sequences**,
…) is rejected with a validation error rather than stored with metadata.

For supported raster uploads, stored/served bytes are rewritten to remove:

- EXIF (including orientation tags after pixels are normalized)
- GPS and other location tags
- XMP and IPTC blocks
- Maker notes and embedded thumbnails
- Camera/device serial numbers, owner names, user comments, and capture timestamps
- GIF comment/application extensions (except the `NETSCAPE2.0` animation block)

**Fail-closed:** if metadata cannot be removed safely, the upload is rejected with a
validation error and nothing is stored with the original metadata. Corrupt or malformed
image bytes, GD parser warnings, and unexpected parser failures are converted to the
same privacy rejection (with developer-oriented log lines that never include file
contents or user identity).

#### Accepted image formats

| Format | Handling |
| --- | --- |
| JPEG (`image/jpeg`, `image/jpg`, `image/pjpeg`, …) | GD re-encode + EXIF orientation (values 1–8) applied before discard |
| PNG | GD re-encode (drops textual/binary metadata chunks) |
| WebP | GD re-encode when `imagecreatefromwebp` / `imagewebp` are available |
| GIF | Comment/XMP application extensions stripped; static GIFs are GD re-encoded; animated GIFs keep frames but lose metadata extensions |
| BMP | GD re-encode when `imagecreatefrombmp` / `imagebmp` are available |
| HEIC/HEIF (single image) | **Rejected unless `ext-imagick` is present**; when present, Imagick `autoOrient` + `stripImage` |

#### Rejected image formats (examples)

`image/tiff`, `image/avif`, `image/jxl`, `image/svg+xml`, `image/x-icon`,
`image/heic-sequence`, `image/heif-sequence`, and any other `image/*` type not listed
above. HEIC/HEIF **burst/sequence** containers are not supported.

#### Listener ordering (Flarum 1.8 / Illuminate 8)

Flarum 1.8 uses Illuminate **8.x** events. `Illuminate\Events\Dispatcher::listen()`
accepts only the event and listener; **there is no priority parameter** (extra
arguments are ignored). Ordering relative to FoF Upload's `AddImageProcessor` is
FIFO registration order on `WillBeUploaded`.

This extension declares an Flarum **optional dependency** on `fof-upload` so its
extenders boot after FoF Upload when both are enabled, and the metadata stripper
listener is registered after FoF's image processor. PHPUnit also exercises both
listener orders (strip before/after a FoF 1.9 image-processor simulation) and
asserts metadata is removed either way.

#### Non-image uploads

This extension does **not** strip metadata from PDFs, video, office documents, or
other non-image MIME types. Those formats can still embed author names, GPS, device
IDs, and timestamps.

#### Member-upload production gate (FlatRate.wiki)

Before **member uploads** are enabled on production, FoF Upload allowed MIME types
must be limited to the privacy-qualified image set documented above (JPEG/PNG/WebP/GIF/BMP,
plus HEIC/HEIF only when Imagick is verified on the host). **PDF, video, and office
documents must remain blocked** until separate sanitizers are qualified for those
families.

#### Runtime image stack (PikaPods / Flarum 1.8.19)

Composer **requires** `ext-gd` and `ext-exif` on the Flarum host. **`ext-imagick` is
optional** — when absent, HEIC/HEIF uploads are rejected rather than stored with metadata.

Imagick is **not** required for JPEG/PNG/WebP/GIF/BMP. Intervention Image (pulled in by
FoF Upload) is not used directly by this extension.

## Deliberate inert design

Runtime `require` depends on:

```text
php: ^8.1
flarum/core: ^1.8.19
ext-gd: *
ext-exif: *
```

(`php` and `flarum/core` version bumps are owned by Release Manager in separate prep PRs.)

`fof/upload` is a `require-dev` / `suggest` operational target, **not** a hard
runtime Composer dependency. The extension remains loadable and enableable when
FoF Upload is installed-but-disabled, and `extend.php` returns an empty extender
list when FoF Upload event classes are absent.

Flarum optional dependency `fof-upload` affects **boot order only** when both
extensions are enabled; it does not add a hard Composer requirement.

This allows FlatRate to enable the privacy guard **before** the first deliberate
FoF Upload enablement (FORUM-MEDIA-001D), without an enable-order dependency that
would force FoF Upload on first.

## Development

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
composer test
```

CI matrix: PHP 8.1, 8.2, 8.3, 8.4 with FoF Upload pinned to `1.9.0`, GD and EXIF
enabled, plus ExifTool for fixtures. Each job runs `composer validate --strict`.

## License

MIT
