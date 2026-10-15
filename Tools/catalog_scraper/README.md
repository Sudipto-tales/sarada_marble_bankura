# Kajaria catalog scraper

Standalone Python 3.10+ CLI for public Kajaria product pages. Uses only the standard
library (Linux/macOS, `fcntl` for single-writer locking). No PHP/Flutter dependency
or database import. Use downloaded content according to your supplier agreement;
collecting it does not grant publication rights.

From the repository root, start with one product:

```bash
python3 Tools/catalog_scraper/kajaria.py --url https://www.kajariaceramics.com/products/breccia-azure --limit 1
```

Discover through the sitemap and collect up to 25 unsaved products:

```bash
python3 Tools/catalog_scraper/kajaria.py
```

Collect a larger batch, including gallery images:

```bash
python3 Tools/catalog_scraper/kajaria.py --limit 200 --delay 5 --download-images
```

Repeat the command to resume. `--limit` bounds product attempts per run, including
failures; sitemap discovery separately permits at most 100 sitemap files, each up
to 32 MiB. Already saved products are skipped. Adding `--download-images` later
downloads images missing from metadata-only runs. Successfully downloaded files
are reused after interruption. Failed products are retried on the next run.
`--refresh` revisits saved pages. Old unreferenced images are retained.

## Collect the full public catalog

The sitemap exposes only a subset. Public listing pagination exposes the rest:
the listing advertises **6,644 products across 554 pages** as of 2026-10-15.
Use the runner to collect all listing pages, followed by gallery downloads:

```bash
bash Tools/catalog_scraper/run_all.sh
```

For background execution with a log:

```bash
mkdir -p var/catalog/kajaria
nohup bash Tools/catalog_scraper/run_all.sh > var/catalog/kajaria/run-all.log 2>&1 < /dev/null &
```

The runner first saves product metadata, then adds images. `--all` traverses up
to 1,000 public listing pages and ignores the normal 25-product limit. Both
phases checkpoint each product and listing page. Run the same command after an
interruption to resume. Existing 25-product sample data is reused. JSON/CSV
exports refresh every 25 attempted products and when the process exits; SQLite
holds every committed product. The runner stops if either phase fails, so inspect
the log before restarting. It does not repeatedly retry an access restriction.

For metadata only, use `python3 Tools/catalog_scraper/kajaria.py --all`.
Options such as `--delay 5` and `--output /private/path` can also be passed to
`run_all.sh`. Progress can be read while the runner is working:

```bash
cat var/catalog/kajaria/progress.json
tail -n 10 var/catalog/kajaria/run-all.log
```

`progress.json` reports advertised/discovered/saved product counts, listing
pages, image file count, failures and status. `complete` after the image phase
means collection finished; it does not mean the products were imported into the
website. A discrepancy between distinct discovered URLs and advertised count
produces `coverage_gap` and a failing exit code. Failed URLs remain in SQLite.
Cached listing pages preserve the traversal across restarts; for a fresh future
catalog snapshot, choose a new output directory. There is no fixed 6,644 cap:
the runner follows pagination until it ends, including newly added pages.

Default output is **`var/catalog/kajaria/`**, ignored by Git and outside the web
source tree. `--output /absolute/private/path` selects another private location;
the tool rejects the repository's Website/ and Html/ trees. Keep custom output
directories out of public hosting and source control.

- `checkpoint.sqlite3`: committed product records and failed URL/error reports.
- `urls.json`: discovered/explicit URL list from the latest run.
- `products.json`: complete accumulated records, including source URLs and UTC timestamps.
- `products.csv`: spreadsheet-friendly export; array cells contain JSON, formula-like scalar strings are escaped.
- `images/`: optional hashed JPEG/PNG/WebP downloads, validated by content type and magic bytes.

`--export-only` rebuilds exports without accessing the network. Exports are also
rebuilt on interruption or access denial from committed checkpoint data. Use a
single process per output directory. Exit 0 means no failures in this run; exit 1
means stopped or some products failed; argument errors exit 2; access restrictions
exit 3. The checkpoint's
`failures` table retains historical failures until a successful retry.

Requests use an identifiable user agent, a minimum one-second interval (default
three seconds), robots.txt rules and crawl delays, same-host HTTPS validation
including redirects, bounded response sizes and timeouts. HTTP 401/403/429 and
recognized verification challenges stop the run. Transient connection/5xx errors
get at most three attempts. No proxies, challenge solvers or private API calls.
Gallery links are downloaded at their published resolution, without guessing
larger URLs. External CDN hosts and other image types are deliberately excluded.

The parser targets the observed `product-title`, `tags`, labelled attribute
sections and lightbox gallery markup. Missing titles fail rather than creating
empty records. Empty or hidden source attributes are retained as supplied; check
the output before use. Category, application, size, finish and color values are
source strings. Price and stock stay null; SKUs and sellable variants need your
own validated mapping. Site changes or JavaScript-only fields may require a
parser update. This output is staging data, **not an OPT-02 import format**.

Initial live verification on 2026-10-15 found 832 unique product URLs in the public sitemap.
This is the discovered subset, not proof of coverage of the site's advertised
tile count. One explicit product parsed successfully with five gallery URLs;
one sitemap-selected product downloaded two images successfully. A full crawl
has not been run.

Verification:

```bash
python3 -m unittest discover -s Tools/catalog_scraper -p 'test_*.py' -v
```
