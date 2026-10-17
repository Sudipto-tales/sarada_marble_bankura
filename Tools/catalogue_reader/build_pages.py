#!/usr/bin/env python3
"""Rasterize the bundled PDF into staged individual reading pages (Poppler + Pillow)."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
from PIL import Image

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('pdf', type=Path)
parser.add_argument('output', type=Path, help='New, empty staging directory; never writes over existing assets')
args = parser.parse_args()
args.output.mkdir(parents=True, exist_ok=False)
pages = []
source_pages = []
with tempfile.TemporaryDirectory(prefix='sarada-catalogue-') as temporary:
    subprocess.run(['pdftoppm', '-png', '-scale-to-y', '1400', '-scale-to-x', '-1',
                    str(args.pdf), str(Path(temporary) / 'source')], check=True)
    originals = sorted(Path(temporary).glob('source-*.png'))
    if not originals:
        raise RuntimeError('PDF produced no pages')
    for source_page, path in enumerate(originals, 1):
        with Image.open(path) as original:
            # KasaMood stores interior sheets as landscape spreads and covers as portrait pages.
            bounds = [(0, 0, original.width, original.height)]
            if original.width > original.height:
                midpoint = original.width // 2
                bounds = [(0, 0, midpoint, original.height),
                          (midpoint, 0, original.width, original.height)]
            for box in bounds:
                filename = f'page-{len(pages) + 1:03d}.jpg'
                original.crop(box).convert('RGB').save(args.output / filename, quality=82, optimize=True)
                pages.append(filename)
                source_pages.append(source_page)
manifest = {'version': hashlib.sha256(args.pdf.read_bytes()).hexdigest()[:16] + '-split-v1',
            'pdfPageCount': len(originals), 'pageCount': len(pages),
            'pages': pages, 'sourcePages': source_pages}
(args.output / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
print(f'{len(originals)} PDF pages -> {len(pages)} reader pages in {args.output}')
