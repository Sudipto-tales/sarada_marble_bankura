#!/usr/bin/env python3
"""Build the bundled Kajaria seed dataset from scraper output.

Reads   var/catalog/kajaria/products.json + images/   (kajaria.py output, private)
Writes  Website/database/seeders/kajaria/catalog.json + images/*.webp

The output is exactly what Website/database/seeders/KajariaCatalogSeeder.php reads.
No network access. Only products whose selected images are all present on disk are
bundled; prices and stock are never invented (the seeder creates hidden drafts).
Requires Pillow with WebP support.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
from collections import Counter
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
DEFAULT_INPUT = REPO / "var" / "catalog" / "kajaria"
DEFAULT_OUTPUT = REPO / "Website" / "database" / "seeders" / "kajaria"
VERSION = "kajaria:v1"  # Seeder idempotency keys depend on this; do not change.
SLUG = re.compile(r"\A[a-z0-9]+(?:-[a-z0-9]+)*\Z")
MAX_SIDE = 800  # Longest edge, matching the originally bundled images.
QUALITY = 80


def slugify(text: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")


def strings(value) -> list[str]:
    if not isinstance(value, list):
        return []
    return [item.strip() for item in value if isinstance(item, str) and item.strip()]


def map_product(record: dict, max_images: int) -> tuple[dict | None, str]:
    """Return (manifest product without image sizes, '') or (None, skip reason).

    The returned product carries a private '_sources' list of scraper-relative image
    paths, in manifest order, which the caller converts and then removes.
    """
    slug = record.get("slug")
    name = record.get("name")
    category = record.get("category")
    if not isinstance(slug, str) or not SLUG.match(slug) or len(slug) > 120:
        return None, "invalid slug"
    if not isinstance(name, str) or not name.strip():
        return None, "missing name"
    if not isinstance(category, str) or not slugify(category):
        return None, "missing category"
    name, category = name.strip(), category.strip()
    # Website limits: slug 120 bytes, product/category names 190 bytes.
    if len(f"Kajaria {name}".encode()) > 190 or len(category.encode()) > 190 or len(slugify(category)) > 120:
        return None, "name or category too long"
    sources = strings(record.get("local_images"))[:max_images]
    if not sources:
        return None, "no downloaded images"

    sizes, finishes = strings(record.get("sizes")), strings(record.get("finishes"))
    colors, applications = strings(record.get("colors")), strings(record.get("applications"))
    description = f"Kajaria {category} — {name}."
    for label, values in (("Size", sizes), ("Finish", finishes), ("Colour", colors), ("Application", applications)):
        if values:
            description += f" {label}: {', '.join(values)}."
    tags = " ".join(["kajaria", "tiles"] + [v.lower() for v in finishes] + [v.lower() for v in colors])
    return {
        "slug": slug,
        "name": f"Kajaria {name}",
        "category_slug": slugify(category),
        "category_name": category,
        "description": description,
        "alt": f"{name} — Kajaria tile",
        "tags": tags,
        "images": [],
        "_sources": sources,
    }, ""


def select(records: list, input_dir: Path, limit: int, max_images: int) -> tuple[list[dict], Counter]:
    """Deterministic selection: valid records sorted by slug, first `limit` kept."""
    skipped: Counter = Counter()
    chosen: dict[str, dict] = {}
    images_root = (input_dir / "images").resolve()
    for record in records:
        if not isinstance(record, dict):
            skipped["malformed record"] += 1
            continue
        product, reason = map_product(record, max_images)
        if product is None:
            skipped[reason] += 1
            continue
        paths = [(input_dir / source).resolve() for source in product["_sources"]]
        if any(images_root not in path.parents for path in paths):
            skipped["image path outside scraper images directory"] += 1
            continue
        if not all(path.is_file() for path in paths):
            skipped["downloaded image file missing"] += 1
            continue
        if product["slug"] in chosen:
            skipped["duplicate slug"] += 1
            continue
        product["_sources"] = paths
        chosen[product["slug"]] = product
    ordered = [chosen[slug] for slug in sorted(chosen)]
    if len(ordered) > limit:
        skipped["over --limit"] += len(ordered) - limit
    return ordered[:limit], skipped


def convert(source: Path, target: Path, force: bool) -> tuple[int, int]:
    """Write `target` as WebP (longest edge at most 800px) unless it already exists; return its size."""
    from PIL import Image

    if force or not target.is_file():
        with Image.open(source) as image:
            image = image.convert("RGBA" if image.mode in ("RGBA", "LA", "P") else "RGB")
            scale = MAX_SIDE / max(image.width, image.height)
            if scale < 1:
                image = image.resize((max(1, round(image.width * scale)), max(1, round(image.height * scale))), Image.LANCZOS)
            temporary = target.with_suffix(".webp.tmp")
            image.save(temporary, "WEBP", quality=QUALITY, method=6)
            os.replace(temporary, target)
    with Image.open(target) as written:
        if written.format != "WEBP":
            raise ValueError(f"{target.name} is not a WebP image")
        return written.width, written.height


def build(input_dir: Path, output_dir: Path, limit: int, max_images: int, force: bool = False) -> dict:
    records = json.loads((input_dir / "products.json").read_text(encoding="utf-8"))
    if not isinstance(records, list):
        raise ValueError("products.json must contain a list of product records")
    products, skipped = select(records, input_dir, limit, max_images)
    if not products:
        raise ValueError("no product has all of its images downloaded; run kajaria.py --download-images first")

    images_dir = output_dir / "images"
    images_dir.mkdir(parents=True, exist_ok=True)
    referenced: set[str] = set()
    for product in products:
        for index, source in enumerate(product.pop("_sources")):
            file = f"{product['slug']}-{index}.webp"
            width, height = convert(source, images_dir / file, force)
            product["images"].append({"file": file, "width": width, "height": height})
            referenced.add(file)

    manifest = {"version": VERSION, "brand_slug": "kajaria", "brand_name": "Kajaria", "products": products}
    temporary = output_dir / "catalog.json.tmp"
    temporary.write_text(json.dumps(manifest, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    os.replace(temporary, output_dir / "catalog.json")

    removed = 0
    for stale in images_dir.iterdir():
        if stale.is_file() and stale.suffix in (".webp", ".tmp") and stale.name not in referenced:
            stale.unlink()
            removed += 1
    return {
        "products": len(products),
        "images": len(referenced),
        "removed_stale_images": removed,
        "skipped": dict(sorted(skipped.items())),
        "bytes": sum((images_dir / file).stat().st_size for file in referenced),
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--input", type=Path, default=DEFAULT_INPUT, help="scraper output directory")
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT, help="bundled seed directory")
    parser.add_argument("--limit", type=int, default=300, help="maximum products to bundle (default 300)")
    parser.add_argument("--max-images", type=int, default=2, help="maximum images per product (default 2)")
    parser.add_argument("--force", action="store_true", help="re-encode images that already exist in the bundle")
    args = parser.parse_args(argv)
    if args.limit < 1 or args.max_images < 1:
        parser.error("--limit and --max-images must be at least 1")
    try:
        summary = build(args.input, args.output, args.limit, args.max_images, args.force)
    except (OSError, ValueError) as error:
        print(f"build_seed: {error}", file=sys.stderr)
        return 1
    print(json.dumps(summary, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
