import json
from pathlib import Path
import tempfile
import unittest

from PIL import Image

import build_seed as b


def record(slug, **overrides):
    base = {
        "slug": slug, "name": slug.title(), "category": "Glazed Vitrified Tiles",
        "sizes": ["60x60 cm"], "finishes": ["Matt"], "colors": ["Cream", "Brown"],
        "applications": ["Wall Tile", "Floor Tile"],
        "local_images": [f"images/{slug}-a.jpg", f"images/{slug}-b.jpg", f"images/{slug}-c.jpg"],
    }
    return base | overrides


class BuildSeedTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.input = Path(self.temp.name) / "in"
        self.output = Path(self.temp.name) / "out"
        (self.input / "images").mkdir(parents=True)

    def write(self, records, missing=()):
        for item in records:
            for relative in (item.get("local_images") or []) if isinstance(item, dict) else []:
                if relative not in missing and relative.startswith("images/"):
                    Image.new("RGB", (1600, 1000), "grey").save(self.input / relative)
        (self.input / "products.json").write_text(json.dumps(records), encoding="utf-8")

    def manifest(self):
        return json.loads((self.output / "catalog.json").read_text(encoding="utf-8"))

    def test_mapping_matches_the_seeder_manifest_shape(self):
        self.write([record("abbey-crema", name="Abbey Crema")])
        b.build(self.input, self.output, 10, 2)
        manifest = self.manifest()
        self.assertEqual({k: manifest[k] for k in ("version", "brand_slug", "brand_name")},
                         {"version": "kajaria:v1", "brand_slug": "kajaria", "brand_name": "Kajaria"})
        self.assertEqual(manifest["products"], [{
            "slug": "abbey-crema",
            "name": "Kajaria Abbey Crema",
            "category_slug": "glazed-vitrified-tiles",
            "category_name": "Glazed Vitrified Tiles",
            "description": "Kajaria Glazed Vitrified Tiles — Abbey Crema. Size: 60x60 cm. Finish: Matt. "
                           "Colour: Cream, Brown. Application: Wall Tile, Floor Tile.",
            "alt": "Abbey Crema — Kajaria tile",
            "tags": "kajaria tiles matt cream brown",
            "images": [{"file": "abbey-crema-0.webp", "width": 800, "height": 500},
                       {"file": "abbey-crema-1.webp", "width": 800, "height": 500}],
        }])
        with Image.open(self.output / "images" / "abbey-crema-0.webp") as image:
            self.assertEqual((image.format, image.size), ("WEBP", (800, 500)))

    def test_empty_attribute_lists_are_omitted(self):
        self.write([record("plain", colors=[], applications=[], sizes=None)])
        b.build(self.input, self.output, 10, 1)
        product = self.manifest()["products"][0]
        self.assertEqual(product["description"], "Kajaria Glazed Vitrified Tiles — Plain. Finish: Matt.")
        self.assertEqual(product["tags"], "kajaria tiles matt")

    def test_skip_rules_and_reasons(self):
        records = [
            record("good"),
            record("no-name", name=" "),
            record("no-category", category=""),
            record("Bad Slug"),
            record("no-images", local_images=[]),
            record("missing-file"),
            record("escape", local_images=["../outside.jpg"]),
            record("good"),
            record("long-name", name="x" * 200),
            "junk",
        ]
        self.write(records, missing={"images/missing-file-a.jpg"})
        summary = b.build(self.input, self.output, 10, 2)
        self.assertEqual([p["slug"] for p in self.manifest()["products"]], ["good"])
        self.assertEqual(summary["skipped"], {
            "downloaded image file missing": 1, "duplicate slug": 1,
            "image path outside scraper images directory": 1, "invalid slug": 1,
            "malformed record": 1, "missing category": 1, "missing name": 1,
            "name or category too long": 1, "no downloaded images": 1,
        })

    def test_order_limit_and_stale_cleanup_are_deterministic(self):
        self.write([record("zeta"), record("alpha"), record("mid")])
        (self.output / "images").mkdir(parents=True)
        (self.output / "images" / "old-0.webp").write_bytes(b"stale")
        (self.output / "images" / "keep.txt").write_text("unrelated")
        summary = b.build(self.input, self.output, 2, 1)
        first = (self.output / "catalog.json").read_bytes()
        self.assertEqual([p["slug"] for p in self.manifest()["products"]], ["alpha", "mid"])
        self.assertEqual((summary["removed_stale_images"], summary["skipped"]), (1, {"over --limit": 1}))
        self.assertEqual(sorted(p.name for p in (self.output / "images").iterdir()),
                         ["alpha-0.webp", "keep.txt", "mid-0.webp"])
        b.build(self.input, self.output, 2, 1)
        self.assertEqual((self.output / "catalog.json").read_bytes(), first)

    def test_existing_bundle_images_are_kept_unless_forced(self):
        self.write([record("alpha")])
        b.build(self.input, self.output, 1, 1)
        target = self.output / "images" / "alpha-0.webp"
        Image.new("RGB", (400, 300), "white").save(target, "WEBP")
        b.build(self.input, self.output, 1, 1)
        self.assertEqual(self.manifest()["products"][0]["images"][0], {"file": "alpha-0.webp", "width": 400, "height": 300})
        b.build(self.input, self.output, 1, 1, force=True)
        self.assertEqual(self.manifest()["products"][0]["images"][0]["width"], 800)

    def test_tall_images_are_bounded_by_their_longest_edge(self):
        self.write([record("alpha", local_images=["images/tall.png"])])
        Image.new("RGBA", (1000, 2000), "white").save(self.input / "images" / "tall.png")
        b.build(self.input, self.output, 1, 1)
        self.assertEqual(self.manifest()["products"][0]["images"][0], {"file": "alpha-0.webp", "width": 400, "height": 800})

    def test_nothing_to_bundle_fails_without_writing(self):
        self.write([record("no-images", local_images=[])])
        self.assertEqual(b.main(["--input", str(self.input), "--output", str(self.output)]), 1)
        self.assertFalse((self.output / "catalog.json").exists())


if __name__ == "__main__":
    unittest.main()
