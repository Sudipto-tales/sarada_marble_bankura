import contextlib
import io
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import patch
from urllib.error import HTTPError
from urllib.robotparser import RobotFileParser

import kajaria as k

HTML = '''<html><meta name="description" content="Sample &amp; stone">
<nav><a href="/products/other">Other</a></nav>
<a data-gallery="gallery" href="/storage/product/sample.jpg?v=1"><img src="thumb.jpg"></a>
<a data-gallery="gallery" href="/storage/product/sample.jpg?v=1">same</a>
<h1 class="product-title">Sample Tile</h1>
<div class="tags"><span>Glazed Vitrified Tiles</span></div>
<div class="tags"><span>Wall Tile - Floor Tile</span></div>
<div><h4>Sizes</h4><p><span>60x60 cm<small>,</small></span></p></div>
<div><h4>Finishes</h4><p><span>Matt,</span></p></div>
<div style="display:none"><h4>Colors</h4><p><span><img alt="Cream"></span></p></div>
<!-- <div class="tags">Wrong</div> -->
<a data-gallery="gallery" href="https://evil.example/image.jpg">External</a></html>'''
URL = k.BASE + "/products/sample"


class ScraperTests(unittest.TestCase):
    def test_listing_cards_next_and_total(self):
        html = '''<span id="product-count">6,644</span>
        <div class="p_items"><a href="/products/sample?page=1">Sample</a></div>
        <nav><a href="/products/not-a-card">Navigation</a></nav>
        <a rel="next" href="/products?page=2">Next</a>'''
        page = k.parse_listing(html, k.BASE + "/products?page=1")
        self.assertEqual(page, {"urls": [URL], "next": k.BASE + "/products?page=2", "expected": 6644})
        for bad in (k.BASE + "/products?page=2&sort=name", k.BASE + "/products?filter=1",
                    k.BASE + "/products?page=0", k.BASE + "/products?page=1&page=2"):
            with self.assertRaises(ValueError):
                k.listing_url(bad)

    def test_all_pages_resume_and_deduplicate(self):
        first = '<span id="product-count">2</span><div class="p_items"><a href="/products/sample">A</a></div><a rel="next" href="/products?page=2">Next</a>'
        second = '<span id="product-count">2</span><div class="p_items"><a href="/products/second">B</a></div>'
        with tempfile.TemporaryDirectory() as directory, contextlib.redirect_stdout(io.StringIO()):
            output = Path(directory)
            (output / "images").mkdir()
            with sqlite3.connect(":memory:") as db:
                db.executescript("CREATE TABLE listing_pages(url TEXT PRIMARY KEY,data TEXT); CREATE TABLE products(url TEXT PRIMARY KEY,data TEXT); CREATE TABLE failures(url TEXT PRIMARY KEY,error TEXT);")
                client = k.Client()
                with patch.object(client, "get", side_effect=[(first.encode(), "text/html"), (second.encode(), "text/html")]) as get:
                    self.assertEqual(list(k.discover_all(client, db, output)), [URL, k.BASE + "/products/second"])
                    self.assertEqual(get.call_count, 2)
                with patch.object(client, "get") as get:
                    self.assertEqual(len(list(k.discover_all(client, db, output))), 2)
                    get.assert_not_called()
                report = k.progress(db, output, "complete")
                self.assertEqual(report["discovered_products"], 2)
                self.assertEqual(report["listing_pages"], 2)

    def test_pagination_repeat_is_rejected(self):
        html = '<div class="p_items"><a href="/products/sample">A</a></div><a rel="next" href="/products?page=1">Next</a>'
        with tempfile.TemporaryDirectory() as directory, sqlite3.connect(":memory:") as db:
            db.execute("CREATE TABLE listing_pages(url TEXT PRIMARY KEY,data TEXT)")
            with patch.object(k.Client, "get", return_value=(html.encode(), "text/html")):
                with self.assertRaises(ValueError):
                    list(k.discover_all(k.Client(), db, Path(directory)))

    def test_all_main_traverses_and_resumes(self):
        first = '<span id="product-count">2</span><div class="p_items"><a href="/products/sample">A</a></div><a rel="next" href="/products?page=2">Next</a>'
        second = '<span id="product-count">2</span><div class="p_items"><a href="/products/second">B</a></div>'
        with tempfile.TemporaryDirectory() as directory, contextlib.redirect_stdout(io.StringIO()):
            with patch.object(k.Client, "load_robots"), patch.object(k.Client, "get", side_effect=[(first.encode(), "text/html"), (HTML.encode(), "text/html"), (second.encode(), "text/html"), (HTML.encode(), "text/html")]):
                self.assertEqual(k.main(["--all", "--output", directory]), 0)
            with patch.object(k.Client, "load_robots"), patch.object(k.Client, "get") as get:
                self.assertEqual(k.main(["--all", "--output", directory]), 0)
                get.assert_not_called()
            report = json.loads((Path(directory) / "progress.json").read_text())
            self.assertEqual(report["status"], "complete")
            self.assertEqual(report["saved_products"], 2)

    def test_product_fields_and_gallery_only(self):
        result = k.parse_product(HTML, URL)
        self.assertEqual(result["name"], "Sample Tile")
        self.assertEqual(result["sizes"], ["60x60 cm"])
        self.assertEqual(result["finishes"], ["Matt"])
        self.assertEqual(result["colors"], ["Cream"])
        self.assertEqual(result["applications"], ["Wall Tile", "Floor Tile"])
        self.assertEqual(result["images"], [k.BASE + "/storage/product/sample.jpg?v=1"])
        self.assertEqual(result["related_urls"], [k.BASE + "/products/other"])
        self.assertIsNone(result["price_minor"])
        self.assertIsNone(result["stock"])

    def test_coverage_gap_is_not_reported_as_complete(self):
        html = '<span id="product-count">3</span><div class="p_items"><a href="/products/sample">A</a></div>'
        with tempfile.TemporaryDirectory() as directory, contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            with patch.object(k.Client, "load_robots"), patch.object(k.Client, "get", side_effect=[(html.encode(), "text/html"), (HTML.encode(), "text/html")]):
                self.assertEqual(k.main(["--all", "--output", directory]), 1)
            report = json.loads((Path(directory) / "progress.json").read_text())
            self.assertEqual(report["status"], "coverage_gap")
            self.assertEqual(report["saved_products"], 1)

    def test_access_stop_preserves_metadata(self):
        with tempfile.TemporaryDirectory() as directory, contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            with patch.object(k.Client, "load_robots"), patch.object(k.Client, "get", side_effect=[(HTML.encode(), "text/html"), k.StopCrawl("HTTP 429")]):
                self.assertEqual(k.main(["--url", URL, "--download-images", "--output", directory]), 3)
            records = json.loads((Path(directory) / "products.json").read_text())
            self.assertEqual(records[0]["source_url"], URL)
            report = json.loads((Path(directory) / "progress.json").read_text())
            self.assertEqual(report["status"], "access_stopped")

    def test_missing_title_rejected(self):
        with self.assertRaises(ValueError):
            k.parse_product("<html>Verification</html>", URL)

    def test_url_boundaries(self):
        for url in ("http://www.kajariaceramics.com/products/a", "https://evil.example/products/a",
                    k.BASE + "/products/a?filter=1", "https://user@www.kajariaceramics.com/products/a",
                    k.BASE + ":444/products/a"):
            with self.subTest(url=url), self.assertRaises(ValueError):
                k.product_url(url)

    def test_nested_sitemap_and_cycle(self):
        index = b'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://www.kajariaceramics.com/child.xml</loc></sitemap></sitemapindex>'
        child = b'<urlset><url><loc>https://www.kajariaceramics.com/products/sample</loc></url><url><loc>https://evil.example/products/no</loc></url><url><loc>https://www.kajariaceramics.com/about</loc></url></urlset>'
        client = k.Client()
        with patch.object(client, "get", side_effect=[(index, "application/xml"), (child, "application/xml")]):
            self.assertEqual(k.discover(client, k.BASE + "/sitemap.xml"), [URL])
        with patch.object(client, "get", return_value=(index, "application/xml")) as get:
            self.assertEqual(k.discover(client, k.BASE + "/child.xml"), [])
            self.assertEqual(get.call_count, 1)

    def test_denied_url_and_redirect_never_fetched(self):
        client = k.Client()
        client.robots = RobotFileParser()
        client.robots.parse(["User-agent: *", "Disallow: /private"])
        with patch.object(client.opener, "open") as request:
            with self.assertRaises(k.StopCrawl):
                client.get(k.BASE + "/private")
            request.assert_not_called()
        with self.assertRaises(k.StopCrawl):
            k.SafeRedirect(client).redirect_request(None, None, 302, "", {}, k.BASE + "/private")

    def test_rate_limit_stops_without_retry(self):
        client = k.Client()
        client.robots = RobotFileParser()
        client.robots.parse(["User-agent: *", "Allow: /"])
        error = HTTPError(URL, 429, "rate limit", {}, None)
        with patch.object(client, "pause"), patch.object(client.opener, "open", side_effect=error) as get:
            with self.assertRaises(k.StopCrawl):
                client.get(URL)
            self.assertEqual(get.call_count, 1)

    def test_resume_and_export(self):
        with tempfile.TemporaryDirectory() as directory, contextlib.redirect_stdout(io.StringIO()):
            args = ["--url", URL, "--output", directory]
            with patch.object(k.Client, "load_robots"), patch.object(k.Client, "get", return_value=(HTML.encode(), "text/html")) as get:
                self.assertEqual(k.main(args), 0)
                self.assertEqual(k.main(args), 0)
                self.assertEqual(get.call_count, 1)
            with patch.object(k.Client, "get") as get:
                self.assertEqual(k.main(["--export-only", "--output", directory]), 0)
                get.assert_not_called()
            data = json.loads((Path(directory) / "products.json").read_text())
            self.assertEqual(len(data), 1)
            self.assertTrue((Path(directory) / "products.csv").exists())

    def test_image_type_validation(self):
        record = k.parse_product(HTML, URL)
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            (output / "images").mkdir()
            client = k.Client()
            with patch.object(client, "get", return_value=(b"<html>challenge</html>", "image/jpeg")):
                with self.assertRaises(ValueError):
                    k.download_images(client, record, output)
            with patch.object(client, "get", return_value=(b"\xff\xd8\xffsample", "image/jpeg")) as get:
                k.download_images(client, record, output)
                k.download_images(client, record, output)
                self.assertEqual(get.call_count, 1)


if __name__ == "__main__":
    unittest.main()
