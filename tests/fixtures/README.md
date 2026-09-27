# Test fixtures

Raster files in this directory come from the public
[ianare/exif-samples](https://github.com/ianare/exif-samples) corpus (CC0 1.0).

| File | Purpose |
| --- | --- |
| `camera_exif.jpg` | Camera EXIF baseline (`Fujifilm_FinePix_E500.jpg`) |
| `orientation_6.jpg` | EXIF orientation value 6 (`orientation/landscape_6.jpg`) |

Tests copy these files to temporary paths before mutation or stripping.
