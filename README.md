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
`mime_content_type()` / `getimagesize()` and treats any upload as an image when either
FoF's detected MIME or the bytes indicate `image/*`.

**Fail-closed image policy:** every image upload must be sanitized or rejected. JPEG
aliases (`image/jpg`, `image/pjpeg`, …) normalize to JPEG. Any other `image/*` type
that cannot be stripped safely (TIFF, AVIF, JPEG XL, SVG, ICO, …) is rejected with a
validation error rather than stored with metadata.

For supported raster uploads, stored/served bytes are rewritten to remove:

- EXIF (including orientation tags after pixels are normalized)
- GPS and other location tags
- XMP and IPTC blocks
- Maker notes and embedded thumbnails
- Camera/device serial numbers, owner names, user comments, and capture timestamps
- GIF comment/application extensions (except the `NETSCAPE2.0` animation block)

**Fail-closed:** if metadata cannot be removed safely, the upload is rejected with a
validation error and nothing is stored with the original metadata.

#### Accepted image formats

| Format | Handling |
| --- | --- |
| JPEG (`image/jpeg`, `image/jpg`, `image/pjpeg`, …) | GD re-encode + EXIF orientation applied before discard |
| PNG | GD re-encode (drops textual/binary metadata chunks) |
| WebP | GD re-encode when `imagecreatefromwebp` / `imagewebp` are available |
| GIF | Comment/XMP application extensions stripped; static GIFs are GD re-encoded; animated GIFs keep frames but lose metadata extensions |
| BMP | GD re-encode when `imagecreatefrombmp` / `imagebmp` are available |
| HEIC/HEIF | **Rejected unless `ext-imagick` is present**; when present, Imagick `autoOrient` + `stripImage` |

#### Rejected image formats (examples)

`image/tiff`, `image/avif`, `image/jxl`, `image/svg+xml`, `image/x-icon`, and any
other `image/*` type not listed above.

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
IDs, and timestamps. Until separate sanitizers exist, **restrict allowed FoF Upload
MIME types** on the forum to formats this extension handles (or non-metadata types
you accept deliberately).

#### Runtime image stack assumption (PikaPods / Flarum 1.8.19)

FlatRate.wiki runs on the managed PikaPods Flarum image (no shell). This extension
depends only on PHP extensions that Flarum itself already requires for avatars and
forum operation:

- **`ext-gd` (required):** primary metadata removal path for JPEG, PNG, WebP, BMP, and static GIF
- **`ext-exif` (required for JPEG orientation):** reads orientation before EXIF is discarded
- **`ext-imagick` (optional):** if absent, **HEIC/HEIF uploads are rejected** rather than stored with metadata

Imagick is **not** required for JPEG/PNG/WebP/GIF/BMP. Intervention Image (pulled in by
FoF Upload) is not used directly by this extension.

## Deliberate inert design

Runtime `require` depends only on:

```text
php: ^8.1
flarum/core: ^1.8.19
```

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

CI matrix: PHP 8.1, 8.2, 8.3, 8.4 with FoF Upload pinned to `1.9.0`. Tests use
ImageMagick/ExifTool on the runner to inject GPS/XMP/EXIF fixtures and verify the
sanitized output.

## License

MIT
