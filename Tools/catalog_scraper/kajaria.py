#!/usr/bin/env python3
"""Polite public catalog collector; staging only, never a commerce DB importer."""
import argparse
import csv
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import sqlite3
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urljoin, urlsplit, urlunsplit
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.robotparser import RobotFileParser
import xml.etree.ElementTree as ET

BASE = "https://www.kajariaceramics.com"
AGENT = "SaradaCatalogBot/1.0"
ROOT = Path(__file__).resolve().parents[2]


class StopCrawl(RuntimeError):
    """Access policy or remote rate limit requires stopping this run."""


def public_url(value, base=BASE):
    url = urlsplit(urljoin(base, value))
    if (url.scheme != "https" or url.hostname != "www.kajariaceramics.com"
            or url.port not in (None, 443) or url.username or url.password):
        raise ValueError("Only HTTPS URLs on www.kajariaceramics.com are supported")
    return urlunsplit(("https", "www.kajariaceramics.com", url.path, url.query, ""))


def product_url(value):
    url = public_url(value)
    parts = urlsplit(url)
    # Listing cards append their pagination context to detail links. It does
    # not identify a different product; remove it before checkpointing.
    if not re.fullmatch(r"/products/[a-zA-Z0-9_-]+/?", parts.path) or (
            parts.query and not re.fullmatch(r"page=[1-9][0-9]*", parts.query)):
        raise ValueError("Expected an unfiltered /products/{slug} URL")
    return urlunsplit((parts.scheme, parts.netloc, parts.path.rstrip("/"), "", ""))


class Node:
    def __init__(self, tag="", attrs=(), parent=None):
        self.tag, self.attrs, self.parent = tag, dict(attrs), parent
        self.children = []

    def walk(self, tag=None):
        for child in self.children:
            if isinstance(child, Node):
                if tag is None or child.tag == tag:
                    yield child
                yield from child.walk(tag)

    def text(self):
        return " ".join(" ".join(c.text() if isinstance(c, Node) else c
                                 for c in self.children).split())


class Document(HTMLParser):
    VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "param", "source", "track", "wbr"}

    def __init__(self, html):
        super().__init__(convert_charrefs=True)
        self.root = self.current = Node()
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        node = Node(tag, attrs, self.current)
        self.current.children.append(node)
        if tag not in self.VOID:
            self.current = node

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        if tag not in self.VOID:
            self.handle_endtag(tag)

    def handle_endtag(self, tag):
        node = self.current
        while node.parent:
            if node.tag == tag:
                self.current = node.parent
                return
            node = node.parent

    def handle_data(self, data):
        self.current.children.append(data)


def unique(items):
    return list(dict.fromkeys(x for x in items if x))


def parse_product(html, url):
    doc = Document(html).root
    title = next((n for n in doc.walk("h1") if "product-title" in n.attrs.get("class", "").split()), None)
    if title is None or not title.text():
        raise ValueError("Product title missing; page layout changed or access challenge returned")
    fields = {}
    for h in doc.walk("h4"):
        label = h.text().lower()
        if label not in {"sizes", "finishes", "colors", "collections", "thickness", "texture"}:
            continue
        container = next(h.parent.walk("p"), None)
        values = []
        if container:
            values = [s.text().strip(" ,") for s in container.walk("span")]
            if label == "colors":
                values = [i.attrs.get("alt", "") for i in container.walk("img")]
            if not any(values):
                values = [v.strip() for v in container.text().split(",")]
        fields[label] = unique(values)
    tags = [n.text() for n in doc.walk("div") if "tags" in n.attrs.get("class", "").split()]
    images, related = [], []
    for a in doc.walk("a"):
        href = a.attrs.get("href", "")
        try:
            if a.attrs.get("data-toggle") == "lightbox" or a.attrs.get("data-gallery"):
                images.append(public_url(href, url))
            elif "/products/" in href:
                candidate = product_url(urljoin(url, href))
                if candidate != url:
                    related.append(candidate)
        except ValueError:
            continue
    description = next((m.attrs.get("content") for m in doc.walk("meta")
                        if m.attrs.get("name") == "description"), None)
    return {"schema_version": 1, "source_url": url, "slug": urlsplit(url).path.split("/")[-1],
            "name": title.text(), "brand": "Kajaria", "category": tags[0] if tags else None,
            "applications": unique(re.split(r"\s+-\s+", tags[1])) if len(tags) > 1 else [],
            "sizes": fields.get("sizes", []), "finishes": fields.get("finishes", []),
            "colors": fields.get("colors", []), "attributes": fields,
            "description": description, "images": unique(images), "local_images": [],
            "related_urls": unique(related), "price_minor": None, "stock": None,
            "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}


class SafeRedirect(HTTPRedirectHandler):
    def __init__(self, client):
        self.client = client

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        target = public_url(newurl)
        if self.client.robots and not self.client.robots.can_fetch(AGENT, target):
            raise StopCrawl("Redirect denied by robots.txt")
        self.client.pause()
        return super().redirect_request(req, fp, code, msg, headers, target)


class Client:
    def __init__(self, delay=3, timeout=30):
        self.delay, self.timeout, self.last = delay, timeout, 0
        self.robots = None
        self.opener = build_opener(SafeRedirect(self))

    def pause(self):
        time.sleep(max(0, self.last + self.delay - time.monotonic()))
        self.last = time.monotonic()

    def get(self, url, limit=8 * 1024 * 1024, robots=False):
        url = public_url(url)
        if not robots and (not self.robots or not self.robots.can_fetch(AGENT, url)):
            raise StopCrawl("robots.txt denies URL: " + url)
        for attempt in range(3):
            self.pause()
            try:
                request = Request(url, headers={"User-Agent": AGENT, "Accept-Encoding": "identity"})
                with self.opener.open(request, timeout=self.timeout) as response:
                    content = response.read(limit + 1)
                    if len(content) > limit:
                        raise ValueError("Response exceeds byte limit")
                    kind = response.headers.get_content_type()
                    if "html" in kind and any(marker in content.lower() for marker in
                            (b"your request is being verified", b"cf-chl-", b"just a moment...")):
                        raise StopCrawl("Verification challenge encountered; stopping")
                    return content, kind
            except HTTPError as exc:
                if exc.code in (401, 403, 429):
                    raise StopCrawl(f"HTTP {exc.code}; stopping without bypass or retry") from exc
                if exc.code < 500 or attempt == 2:
                    raise
            except (URLError, TimeoutError):
                if attempt == 2:
                    raise
            time.sleep(2 ** attempt)

    def load_robots(self):
        content, kind = self.get(BASE + "/robots.txt", limit=512 * 1024, robots=True)
        if kind != "text/plain" or b"<html" in content.lower():
            raise StopCrawl("Expected plain-text robots.txt; access policy could not be verified")
        self.robots = RobotFileParser()
        self.robots.parse(content.decode("utf-8").splitlines())
        self.delay = max(self.delay, self.robots.crawl_delay(AGENT) or 0)
        rate = self.robots.request_rate(AGENT)
        if rate:
            self.delay = max(self.delay, rate.seconds / rate.requests)


def discover(client, sitemap, max_sitemaps=100):
    pending, visited, products = [public_url(sitemap)], set(), set()
    while pending:
        url = pending.pop(0)
        if url in visited:
            continue
        if len(visited) >= max_sitemaps:
            raise ValueError("Sitemap count limit exceeded")
        visited.add(url)
        body, _ = client.get(url, limit=32 * 1024 * 1024)
        root = ET.fromstring(body)
        kind = root.tag.rsplit("}", 1)[-1]
        if kind not in {"sitemapindex", "urlset"}:
            raise ValueError("Expected XML sitemap")
        for node in root:
            loc = next((c.text for c in node if c.tag.rsplit("}", 1)[-1] == "loc"), None)
            if not loc:
                continue
            try:
                if kind == "sitemapindex":
                    candidate = public_url(loc)
                    if candidate not in visited and candidate not in pending:
                        pending.append(candidate)
                else:
                    products.add(product_url(loc))
            except ValueError:
                continue
    return sorted(products)


def atomic_write(path, data):
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_bytes(data)
    temporary.replace(path)


def listing_url(value):
    url = public_url(value)
    parts = urlsplit(url)
    query = parse_qs(parts.query, keep_blank_values=True)
    if parts.path.rstrip("/") != "/products" or (parts.query and
            (list(query) != ["page"] or len(query["page"]) != 1
             or not re.fullmatch(r"[1-9][0-9]{0,3}", query["page"][0]))):
        raise ValueError("Expected an unfiltered product listing page")
    page = int(query.get("page", ["1"])[0])
    return BASE + "/products?page=" + str(page), page


def parse_listing(html, url):
    doc = Document(html).root
    urls = []
    for card in doc.walk("div"):
        if "p_items" in card.attrs.get("class", "").split():
            link = next(card.walk("a"), None)
            if link:
                urls.append(product_url(urljoin(url, link.attrs.get("href", ""))))
    if not urls:
        raise ValueError("No product cards; listing changed or access challenge returned")
    next_link = next((a for a in doc.walk("a") if "next" in a.attrs.get("rel", "").split()), None)
    next_url = listing_url(urljoin(url, next_link.attrs["href"]))[0] if next_link else None
    count = next((n.text() for n in doc.walk("span") if n.attrs.get("id") == "product-count"), None)
    expected = int(count.replace(",", "")) if count and count.replace(",", "").isdigit() else None
    return {"urls": unique(urls), "next": next_url, "expected": expected}


def discover_all(client, db, output):
    """Yield each listing's products immediately, checkpointing pagination first."""
    url, seen, visited = BASE + "/products?page=1", set(), set()
    while url:
        if url in visited or len(visited) >= 1000:
            raise ValueError("Listing pagination cycle or 1,000-page limit reached")
        visited.add(url)
        cached = db.execute("SELECT data FROM listing_pages WHERE url=?", (url,)).fetchone()
        if cached:
            page = json.loads(cached[0])
        else:
            body, kind = client.get(url)
            if kind != "text/html":
                raise ValueError("Expected HTML listing")
            page = parse_listing(body.decode("utf-8"), url)
            if page["next"] and listing_url(page["next"])[1] != listing_url(url)[1] + 1:
                raise ValueError("Pagination did not advance to the next page")
            with db:
                db.execute("INSERT INTO listing_pages VALUES (?,?)", (url, json.dumps(page)))
            print(f"Listing page {listing_url(url)[1]}: {len(page['urls'])} products; advertised={page['expected']}", flush=True)
            progress(db, output, "running", write_urls=True)
        for candidate in page["urls"]:
            if candidate not in seen:
                seen.add(candidate)
                yield candidate
        url = page["next"]
    print(f"Listing exhausted: {len(seen)} distinct product URLs", flush=True)


def progress(db, output, status, images_requested=False, write_urls=False):
    pages = [json.loads(r[0]) for r in db.execute("SELECT data FROM listing_pages")]
    discovered = sorted({u for page in pages for u in page["urls"]})
    expected = max((p["expected"] or 0 for p in pages), default=0)
    report = {"status": status, "listing_pages": len(pages), "advertised_products": expected or None,
              "discovered_products": len(discovered), "saved_products": db.execute("SELECT COUNT(*) FROM products").fetchone()[0],
              "failed_products": db.execute("SELECT COUNT(*) FROM failures").fetchone()[0],
              "images_requested": images_requested,
              "downloaded_images": len(list((output / "images").glob("*"))),
              "updated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
    atomic_write(output / "progress.json", json.dumps(report, indent=2).encode())
    if pages and write_urls:
        atomic_write(output / "urls.json", json.dumps(discovered, indent=2).encode())
    return report


def download_images(client, record, output):
    paths = []
    for url in record["images"]:
        digest = hashlib.sha256(url.encode()).hexdigest()
        existing = sorted((output / "images").glob(digest + ".*"))
        if existing:
            paths.append(str(existing[0].relative_to(output)))
            continue
        body, kind = client.get(url, limit=25 * 1024 * 1024)
        formats = {"image/jpeg": (".jpg", body.startswith(b"\xff\xd8\xff")),
                   "image/png": (".png", body.startswith(b"\x89PNG\r\n\x1a\n")),
                   "image/webp": (".webp", body.startswith(b"RIFF") and body[8:12] == b"WEBP")}
        if kind not in formats or not formats[kind][1]:
            raise ValueError("Unsupported image content; expected JPEG, PNG or WebP")
        target = output / "images" / (digest + formats[kind][0])
        atomic_write(target, body)
        paths.append(str(target.relative_to(output)))
    record["local_images"] = paths


def export(db, output):
    records = [json.loads(row[0]) for row in db.execute("SELECT data FROM products ORDER BY url")]
    atomic_write(output / "products.json", json.dumps(records, ensure_ascii=False, indent=2).encode())
    target = output / "products.csv.tmp"
    keys = ["slug", "name", "brand", "category", "applications", "sizes", "finishes", "colors",
            "description", "images", "local_images", "source_url", "price_minor", "stock"]
    with target.open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=keys)
        writer.writeheader()
        for record in records:
            row = {key: record.get(key) for key in keys}
            for key, value in row.items():
                if isinstance(value, list):
                    row[key] = json.dumps(value, ensure_ascii=False)
                elif isinstance(value, str) and value.lstrip().startswith(("=", "+", "-", "@")):
                    row[key] = "'" + value
            writer.writerow(row)
    target.replace(output / "products.csv")


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", action="append", default=[], help="Explicit product URL; repeatable")
    parser.add_argument("--sitemap", default=BASE + "/sitemap.xml")
    parser.add_argument("--all", action="store_true", help="Traverse all public listing pages; ignores --limit")
    parser.add_argument("--output", type=Path, default=ROOT / "var/catalog/kajaria")
    parser.add_argument("--limit", type=int, default=25, help="Maximum products attempted this run")
    parser.add_argument("--delay", type=float, default=3, help="Minimum seconds between requests (>=1)")
    parser.add_argument("--timeout", type=float, default=30)
    parser.add_argument("--download-images", action="store_true")
    parser.add_argument("--refresh", action="store_true", help="Revisit saved products")
    parser.add_argument("--export-only", action="store_true", help="Regenerate exports without network")
    args = parser.parse_args(argv)
    if args.all and args.url:
        parser.error("--all cannot be combined with --url")
    if args.limit < 1 or not 1 <= args.delay <= 3600 or not 1 <= args.timeout <= 120:
        parser.error("limit must be positive; delay 1..3600; timeout 1..120")
    output = args.output.resolve()
    if output.is_relative_to(ROOT / "Website") or output.is_relative_to(ROOT / "Html"):
        parser.error("Output must be outside public Website/ and Html/ trees")
    output.mkdir(parents=True, exist_ok=True)
    (output / "images").mkdir(exist_ok=True)
    # One writer per output directory; OS releases lock even after a crash.
    import fcntl
    with (output / "run.lock").open("w") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            parser.error("Another scraper is using this output directory")
        with sqlite3.connect(output / "checkpoint.sqlite3") as db:
            db.execute("CREATE TABLE IF NOT EXISTS products(url TEXT PRIMARY KEY, data TEXT NOT NULL)")
            db.execute("CREATE TABLE IF NOT EXISTS failures(url TEXT PRIMARY KEY, error TEXT NOT NULL)")
            db.execute("CREATE TABLE IF NOT EXISTS listing_pages(url TEXT PRIMARY KEY, data TEXT NOT NULL)")
            failures = 0
            status = "exported" if args.export_only else "running"
            try:
                if not args.export_only:
                    client = Client(args.delay, args.timeout)
                    client.load_robots()
                    if args.all:
                        urls = discover_all(client, db, output)
                    else:
                        urls = unique(product_url(u) for u in args.url) if args.url else discover(client, args.sitemap)
                        atomic_write(output / "urls.json", json.dumps(urls, indent=2).encode())
                    attempted = 0
                    for url in urls:
                        saved = db.execute("SELECT data FROM products WHERE url=?", (url,)).fetchone()
                        if saved and not args.refresh:
                            record = json.loads(saved[0])
                            if not args.download_images or (len(record["local_images"]) == len(record["images"])
                                    and all((output / p).is_file() for p in record["local_images"])):
                                continue
                        if not args.all and attempted >= args.limit:
                            break
                        attempted += 1
                        try:
                            if saved and not args.refresh:
                                record = json.loads(saved[0])
                            else:
                                body, kind = client.get(url)
                                if kind != "text/html":
                                    raise ValueError("Expected HTML product page")
                                record = parse_product(body.decode("utf-8"), url)
                            # Preserve metadata even if image fetching is interrupted.
                            with db:
                                db.execute("INSERT OR REPLACE INTO products VALUES (?,?)", (url, json.dumps(record)))
                            if args.download_images:
                                download_images(client, record, output)
                            with db:
                                db.execute("INSERT OR REPLACE INTO products VALUES (?,?)", (url, json.dumps(record)))
                                db.execute("DELETE FROM failures WHERE url=?", (url,))
                            print("Saved " + url, flush=True)
                        except StopCrawl:
                            raise
                        except (ValueError, URLError, TimeoutError) as exc:
                            failures += 1
                            with db:
                                db.execute("INSERT OR REPLACE INTO failures VALUES (?,?)", (url, str(exc)))
                            print(f"Failed {url}: {exc}", file=sys.stderr, flush=True)
                        progress(db, output, "running", args.download_images)
                        if attempted % 25 == 0:
                            export(db, output)
                    status = "complete" if not failures else "finished_with_errors"
                    if args.all:
                        report = progress(db, output, status, args.download_images)
                        if report["advertised_products"] and report["discovered_products"] < report["advertised_products"]:
                            status = "coverage_gap"
                            failures += 1
                            print("Listing exhausted below advertised count; inspect progress.json for coverage gap", file=sys.stderr)
            except StopCrawl as exc:
                status = "access_stopped"
                print(f"Stopped: {exc}", file=sys.stderr)
                failures += 1
            except (ValueError, URLError, TimeoutError, ET.ParseError, KeyboardInterrupt) as exc:
                status = "interrupted"
                print(f"Stopped: {exc}", file=sys.stderr)
                failures += 1
            finally:
                export(db, output)
                progress(db, output, status, args.download_images)
            print(f"Exports: {output}; saved={db.execute('SELECT COUNT(*) FROM products').fetchone()[0]}; run_failures={failures}")
            return 3 if status == "access_stopped" else (1 if failures else 0)


if __name__ == "__main__":
    sys.exit(main())
