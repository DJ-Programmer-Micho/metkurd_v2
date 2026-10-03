"""Regenerate committed public landing variants; requires Pillow locally, not at runtime.

Keeps source artwork untouched. QR images are encoded losslessly at their original
dimensions; logos are downsampled for the existing 22 CSS-pixel display slot.
"""

from pathlib import Path

from PIL import Image

root = Path(__file__).resolve().parents[1]
output = root / "public/landing/images"
output.mkdir(parents=True, exist_ok=True)

for name in ("black_logo", "white_logo"):
    with Image.open(root / f"public/app/logo/{name}.png") as source:
        for width in (44, 88):
            size = (width, round(source.height * width / source.width))
            variant = source.resize(size, Image.Resampling.LANCZOS)
            target = output / f"{name}-{width}.webp"
            variant.save(target, format="WEBP", lossless=True, method=6)
            print(target.name, size, target.stat().st_size)

for name in ("qr_tele", "google_review"):
    with Image.open(root / f"public/app/logo/{name}.png") as source:
        target = output / f"{name}.webp"
        source.save(target, format="WEBP", lossless=True, method=6)
        print(target.name, source.size, target.stat().st_size)
