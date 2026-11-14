Kajaria Ceramics Product Data Pilot
=====================================
Date: 2026-11-14
Source: https://www.kajariaceramics.com/products

CONTENTS
--------
excel/kajaria_products_pilot.xlsx  - 20 products with full schema
metadata/products.json             - Same data in JSON
images/                            - Image placeholders (see note below)

CATEGORY MAPPING USED
---------------------
category_type : Tiles
category      : Glazed Vitrified Tiles   (for all products in this pilot)

All products in this pilot belong to Glazed Vitrified Tiles on the source site.

IMAGE NOTE
----------
The official site uses dynamic loading + Cloudflare protection.
Full-resolution gallery images could not be reliably downloaded in this automated session.
The Excel column 'image_local' shows the intended naming (kajariya1.jpg ... kajariya20.jpg).
When you run a proper Playwright scraper on your machine you can replace the placeholders with real files.

HOW TO EXTEND TO FULL CATALOG
-----------------------------
1. Use Playwright (stealth) to load /products and paginate / filter.
2. Collect every product URL.
3. Visit each product page, extract the fields shown in the Excel.
4. Download gallery images and rename sequentially kajariyaN.jpg.
5. Keep the same Excel schema.

This pilot demonstrates the exact data structure and category assignment you requested.
