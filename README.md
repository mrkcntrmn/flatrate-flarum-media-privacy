# flatrate/flarum-media-privacy

FlatRate.wiki Flarum extension that replaces FoF Upload user-derived basenames
with cryptographically random opaque basenames.

| Field | Value |
| --- | --- |
| Composer package | `flatrate/flarum-media-privacy` |
| Flarum extension ID | `flatrate-flarum-media-privacy` |
| Stable target | `1.0.0` |
| FoF integration | `fof/upload` **1.9.0** event `FoF\Upload\Events\File\IsSlugged` |

## Responsibility

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

## Deliberate inert design

Runtime `require` depends only on:

```text
php: ^8.1
flarum/core: ^1.8.19
```

`fof/upload` is a `require-dev` / `suggest` operational target, **not** a hard
runtime Composer dependency. The extension remains loadable and enableable when
FoF Upload is installed-but-disabled, and `extend.php` returns an empty extender
list when the FoF `IsSlugged` class is absent.

This allows FlatRate to enable the privacy guard **before** the first deliberate
FoF Upload enablement (FORUM-MEDIA-001D), without an enable-order dependency that
would force FoF Upload on first.

## Non-goals

```text
OPAQUE_BASENAME_REMEDIATION=IMPLEMENTED
EXIF_SANITIZATION=NOT_IMPLEMENTED
EXIF_SANITIZER_REQUIRED=UNKNOWN_PENDING_001E_FIXTURE
```

This package does not strip GPS EXIF, camera make/model, capture timestamps, or
other embedded image metadata. It has no frontend JS, admin UI, CSS, routes,
settings, migrations, permissions, cron, queues, Cloudflare, R2, or identity /
SSO coupling.

## Development

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
composer test
```

CI matrix: PHP 8.1, 8.2, 8.3, 8.4 with FoF Upload pinned to `1.9.0`.

## License

MIT
