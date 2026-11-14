"""
Kajaria Ceramics Product Scraper
--------------------------------
- Uses Playwright (handles Cloudflare)
- Extracts fields compatible with the PHP Vayu KajariaCatalogSeeder
- Downloads images and renames them sequentially
- Generates catalog.json manifest for CLI import
- Rate-limited & resumable

Run:  python scraper.py
"""

import asyncio
import json
import os
import re
import shutil
import sys
import gzip
import time
from datetime import datetime
from pathlib import Path
from urllib.parse import urljoin
from urllib.request import Request, urlopen

import aiofiles
import aiohttp
import pandas as pd
from PIL import Image
from playwright.async_api import async_playwright, TimeoutError as PlaywrightTimeout
from tqdm.asyncio import tqdm

# ====================== CONFIG ======================
BASE_URL = "https://www.kajariaceramics.com"
PRODUCTS_LIST_URL = "https://www.kajariaceramics.com/products"
OUTPUT_DIR = Path("output")
IMAGES_DIR = OUTPUT_DIR / "images"
EXCEL_DIR = OUTPUT_DIR / "excel"
META_DIR = OUTPUT_DIR / "metadata"
KAJARIA_SEEDER_DIR = Path("/home/luv/Projects/sarada_marble_bankura/Website/database/seeders/kajaria")

MAX_PRODUCTS = 50          # Start small (pilot). Set to None for full catalog
CONCURRENT_DOWNLOADS = 4   # Keep low to avoid bans
DELAY_BETWEEN_PRODUCTS = 1.5  # seconds
HEADLESS = True            # Set False to watch the browser

# Category mapping: source_category → (category_slug, category_name)
CATEGORY_MAP = {
    "glazed vitrified tiles": ("glazed-vitrified-tiles", "Glazed Vitrified Tiles"),
    "polished vitrified tiles": ("polished-vitrified-tiles", "Polished Vitrified Tiles"),
    "ceramic": ("ceramic-wall-tiles", "Ceramic Wall Tiles"),
    "wall tiles": ("ceramic-wall-tiles", "Ceramic Wall Tiles"),
    "floor tiles": ("polished-vitrified-tiles", "Polished Vitrified Tiles"),
    "outdoor": ("glazed-vitrified-tiles", "Glazed Vitrified Tiles"),
    "elevation": ("glazed-vitrified-tiles", "Glazed Vitrified Tiles"),
    "countertop": ("polished-vitrified-tiles", "Polished Vitrified Tiles"),
}


def ensure_dirs():
    for d in [IMAGES_DIR, EXCEL_DIR, META_DIR, KAJARIA_SEEDER_DIR / "images"]:
        d.mkdir(parents=True, exist_ok=True)


def map_category(source_cat: str) -> tuple:
    """
    Return (category_slug, category_name, category_type)
    Based on the existing catalog.json format in the PHP seeder.
    """
    if not source_cat:
        return "polished-vitrified-tiles", "Polished Vitrified Tiles", "Tiles"
    lower = source_cat.lower()
    for key, (slug, name) in CATEGORY_MAP.items():
        if key in lower:
            return slug, name, "Tiles"
    return "polished-vitrified-tiles", "Polished Vitrified Tiles", "Tiles"


def generate_slug(name: str, source_url: str) -> str:
    """Generate a slug similar to the existing catalog: lowercase, hyphenated"""
    m = re.search(r'[\d]+[-\s][\dA-Z]+', name or "")
    if m:
        code = m.group(0).replace(" ", "-").replace("--", "-")
        return re.sub(r'[^a-z0-9-]', '', code.lower())

    text = (name or source_url or "product").lower()
    text = re.sub(r'[^\w\s-]', '', text)
    text = re.sub(r'\s+', '-', text)
    text = re.sub(r'-+', '-', text)
    text = text.strip('-')
    return text[:50] or "product"


def slugify(text: str) -> str:
    """Convert text to slug format"""
    text = text.lower()
    text = re.sub(r'[^\w\s-]', '', text)
    text = re.sub(r'\s+', '-', text)
    text = re.sub(r'-+', '-', text)
    text = text.strip('-')
    return text


def _generate_tags(sizes: list, finishes: list) -> str:
    """Generate space-separated tags from sizes and finishes"""
    tags = []
    for s in (sizes or [])[:3]:
        if s:
            tags.append(s.replace(" cm", "").replace(" mm", ""))
    for f in (finishes or [])[:3]:
        if f:
            tags.append(f.lower())
    return " ".join(tags[:5]) if tags else "kajaria tiles"


def download_image_synchronous(url: str, save_path: Path) -> bool:
    """Download an image synchronously using urllib with proper headers"""
    try:
        req = Request(url, headers={
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
            "Accept": "image/avif,image/webp,image/apng,image/*,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.5",
            "Referer": BASE_URL,
        })
        with urlopen(req, timeout=30) as response:
            content = response.read()
        # Write content to file
        with open(save_path, "wb") as f:
            f.write(content)
        return True
    except Exception as e:
        print(f"  [img fail] {url[:60]}... → {e}")
    return False


async def download_image_async(session, url: str, save_path: Path) -> bool:
    """Download an image asynchronously using aiohttp"""
    try:
        async with session.get(url, timeout=30) as resp:
            if resp.status == 200:
                content = await resp.read()
                # Handle brotli decompression if needed
                try:
                    import brotli
                    content = brotli.decompress(content)
                except (ImportError, brotli.error):
                    pass  # Not brotli, use as-is
                # Handle gzip
                try:
                    content = gzip.decompress(content)
                except (gzip.BadGzipFile, OSError):
                    pass  # Not gzip
                async with aiofiles.open(save_path, "wb") as f:
                    await f.write(content)
                return True
    except Exception as e:
        print(f"  [img fail] {url[:60]}... → {e}")
    return False


async def scrape_product_page(page, url: str, img_counter: list) -> dict | None:
    """Extract one product according to the catalog.json schema"""
    try:
        await page.goto(url, wait_until="domcontentloaded", timeout=45000)
        await page.wait_for_timeout(1200)

        # ----- Basic fields -----
        name = await page.locator("h1").first.inner_text() if await page.locator("h1").count() else None
        name = name.strip() if name else None

        # Source category
        source_category = None
        try:
            cats = await page.locator("a, span, div").all_inner_texts()
            for t in cats:
                if "vitrified" in t.lower() or "ceramic" in t.lower() or "tile" in t.lower():
                    source_category = t.strip()
                    break
        except:
            pass

        category_slug, category_name, category_type = map_category(source_category or "")

        # Sizes
        sizes = []
        try:
            size_text = await page.locator("text=/\\d+x\\d+/i").all_inner_texts()
            sizes = list(set([s.strip() for s in size_text if re.search(r"\d", s)]))
        except Exception:
            sizes = []

        # Finishes
        finishes = []
        try:
            fin = await page.locator("text=/polish|matt|satin|decor|marbletech|gloss/i").all_inner_texts()
            finishes = list(set([f.strip() for f in fin if len(f) < 40]))
        except Exception:
            finishes = []

        # Applications
        applications = []
        try:
            apps = await page.locator("text=/wall|floor|outdoor|kitchen|bathroom/i").all_inner_texts()
            applications = list(set([a.strip() for a in apps if len(a) < 50]))[:5]
        except Exception:
            applications = []

        # Images
        img_urls = []
        try:
            imgs = await page.locator("img").all()
            for img in imgs:
                src = await img.get_attribute("src") or await img.get_attribute("data-src")
                if src and ("product" in src.lower() or "tile" in src.lower() or "cdn" in src.lower()):
                    full = urljoin(BASE_URL, src)
                    if full not in img_urls and not full.endswith((".svg", ".gif")):
                        img_urls.append(full)
        except:
            pass

        # Build image list with local names
        images = []
        for i, img_url in enumerate(img_urls[:6]):  # max 6 images per product
            img_counter[0] += 1
            local_name = f"{slugify(name or 'product')}-{img_counter[0]:02d}.webp"
            images.append({
                "url": img_url,
                "source_page": url,
                "alt_text": name or "",
                "local_filename": local_name
            })

        # Generate slug for this product
        slug = generate_slug(name or "", url)

        product = {
            "slug": slug,
            "name": name,
            "category_slug": category_slug,
            "category_name": category_name,
            "description": f"{name} – {source_category or 'Glazed Vitrified Tiles'}. Sizes: {', '.join(sizes) if sizes else 'N/A'}",
            "alt": name or "",
            "tags": _generate_tags(sizes, finishes),
            "images": images,
            "source_url": url,
            "captured_at": datetime.now().strftime("%Y-%m-%d"),
            "confidence": "high" if name and source_category else "medium",
            "verification_note": "Extracted via Playwright. Price/SKU/stock not published on page."
        }
        return product

    except Exception as e:
        print(f"Error on {url}: {e}")
        return None


async def get_product_links(page, max_products: int | None) -> list[str]:
    """Collect product detail URLs from the listing page"""
    print("Loading products listing...")
    await page.goto(PRODUCTS_LIST_URL, wait_until="networkidle", timeout=60000)
    await page.wait_for_timeout(3000)

    # Scroll to load more
    for _ in range(8):
        await page.mouse.wheel(0, 3000)
        await page.wait_for_timeout(800)

    links = set()
    anchors = await page.locator("a[href*='/products/']").all()
    for a in anchors:
        href = await a.get_attribute("href")
        if href and "/products/" in href and href.count("/") >= 2:
            full = urljoin(BASE_URL, href.split("?")[0])
            if full != PRODUCTS_LIST_URL:
                links.add(full)

    links = list(links)
    print(f"Found {len(links)} product links")
    if max_products:
        links = links[:max_products]
    return links


async def main():
    ensure_dirs()
    img_counter = [0]          # mutable counter for image naming
    all_products = []

    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=HEADLESS,
            args=["--disable-blink-features=AutomationControlled"]
        )
        context = await browser.new_context(
            viewport={"width": 1400, "height": 900},
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
        )
        page = await context.new_page()

        # 1. Collect links
        product_urls = await get_product_links(page, MAX_PRODUCTS)

        # 2. Scrape each product
        print(f"\nScraping {len(product_urls)} products...")
        for url in tqdm(product_urls):
            product = await scrape_product_page(page, url, img_counter)
            if product:
                all_products.append(product)
            await asyncio.sleep(DELAY_BETWEEN_PRODUCTS)

        await browser.close()

    # 3. Download images using synchronous method (more reliable with Cloudflare)
    print("\nDownloading images...")
    downloaded = 0
    for prod_idx, prod in enumerate(all_products):
        for img_idx, img in enumerate(prod["images"]):
            save_path = IMAGES_DIR / img["local_filename"]
            if not save_path.exists():
                success = download_image_synchronous(img["url"], save_path)
                if success:
                    downloaded += 1
                await asyncio.sleep(0.1)  # Rate limit

    # 4. Resize all downloaded images to WebP using Pillow
    print("Resizing images to WebP...")
    for img_file in sorted(IMAGES_DIR.glob("*.jpg")):
        webp_path = IMAGES_DIR / (img_file.stem + ".webp")
        if not webp_path.exists() and img_file.stat().st_size > 0:
            try:
                with Image.open(img_file) as img:
                    if img.mode == "RGBA":
                        img = img.convert("RGB")
                    ratio = 800 / img.width
                    new_height = int(img.height * ratio)
                    new_width = 800
                    img = img.resize((new_width, new_height), Image.LANCZOS)
                    img.save(webp_path, "WEBP", quality=85)
            except Exception as e:
                print(f"  [resize error] {img_file.name}: {e}")
                try:
                    img = Image.new('RGB', (800, 600), color=(128, 128, 128))
                    img.save(webp_path, "WEBP", quality=85)
                except:
                    pass
        img_file.unlink(missing_ok=True)  # Remove original JPG

    # 5. Copy WebP images to Kajaria seeder images directory and generate catalog.json
    print("Copying images to seeder directory and generating catalog.json...")
    catalog = {
        "version": "kajaria:v1",
        "brand_slug": "kajaria",
        "brand_name": "Kajaria",
        "products": []
    }

    for prod in all_products:
        product_entry = {
            "slug": prod["slug"],
            "name": prod["name"],
            "category_slug": prod["category_slug"],
            "category_name": prod["category_name"],
            "description": prod["description"],
            "alt": prod["alt"],
            "tags": prod["tags"],
            "images": []
        }

        # Process images - copy to seeder images dir
        for img_idx, img in enumerate(prod["images"]):
            source_img = IMAGES_DIR / img["local_filename"]

            if source_img.exists():
                # Copy to seeder images dir
                dest_img = KAJARIA_SEEDER_DIR / "images" / img["local_filename"]
                if not dest_img.exists():
                    (KAJARIA_SEEDER_DIR / "images").mkdir(parents=True, exist_ok=True)
                    shutil.copy2(source_img, dest_img)

                # Read actual dimensions from the WebP file
                try:
                    with Image.open(dest_img) as img_meta:
                        actual_width, actual_height = img_meta.size
                except:
                    actual_width, actual_height = 800, 573

                img_data = {
                    "file": img["local_filename"],
                    "width": actual_width,
                    "height": actual_height
                }
                product_entry["images"].append(img_data)

        catalog["products"].append(product_entry)

    # Write catalog.json
    manifest_path = KAJARIA_SEEDER_DIR / "catalog.json"
    with open(manifest_path, "w", encoding="utf-8") as f:
        json.dump(catalog, f, indent=2, ensure_ascii=False)
    print(f"Catalog manifest saved → {manifest_path}")

    # 6. Save Excel (original schema)
    print("\nSaving Excel...")
    rows = []
    for p in all_products:
        row = {
            "name": p["name"],
            "brand": "Kajaria",
            "description": p["description"],
            "source_category": p.get("source_category", ""),
            "category_type": p.get("category_type", "Tiles"),
            "category": p["category_name"],
            "sizes": ", ".join(p.get("sizes", [])) if p.get("sizes") else None,
            "finishes": ", ".join(p.get("finishes", [])) if p.get("finishes") else None,
            "colours": None,
            "material": p.get("material", ""),
            "thickness": None,
            "applications": ", ".join(p.get("applications", [])) if p.get("applications") else None,
            "tags": p.get("tags", ""),
            "source_url": p["source_url"],
            "captured_at": p["captured_at"],
            "confidence": p["confidence"],
        }
        rows.append(row)

    df = pd.DataFrame(rows)
    excel_path = EXCEL_DIR / "kajaria_products.xlsx"
    df.to_excel(excel_path, index=False)
    print(f"Excel saved → {excel_path}")

    # 7. Save full JSON metadata
    json_path = META_DIR / "products.json"
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump({"products": all_products, "total": len(all_products)}, f, indent=2, ensure_ascii=False)
    print(f"JSON saved  → {json_path}")
    print(f"Images      → {IMAGES_DIR} (processed)")
    print("Done.")


if __name__ == "__main__":
    asyncio.run(main())