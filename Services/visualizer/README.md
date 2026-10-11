# Local SAM 2 photo segmentation

This is the SAM-01 local inference slice, used by the SAM-02 website prototype.
It runs on a local photo and emits a self-contained result file; it does not
start an HTTP server or upload photos. Production API/queue integration remains
SAM-03/04. See [delivery plan](../../Docs/room-visualizer-sam2-plan.md).

## Setup

Use Python 3.10+ with venv/pip available. In this directory:

```sh
python3 -m venv .venv
.venv/bin/pip install setuptools wheel
.venv/bin/pip install torch==2.5.1 torchvision==0.20.1 --index-url https://download.pytorch.org/whl/cpu
SAM2_BUILD_CUDA=0 .venv/bin/pip install --no-build-isolation -r requirements.txt
```

These commands are for a CPU evaluation environment. For CUDA, install the
appropriate official PyTorch build and follow upstream extension requirements.
Dependencies pin direct versions and the SAM source commit; requirements.txt is
not a complete transitive lockfile. A production environment lock is a later
deployment gate.

Download trusted checkpoints from the links in the
[official SAM 2 README](https://github.com/facebookresearch/sam2) into `models/`.
Use `sam2.1_hiera_tiny.pt` with `--model tiny`, or `sam2.1_hiera_small.pt` with
`--model small`. Checkpoints are Python model artifacts: never accept arbitrary
checkpoints from photo-uploading customers. Models/environments/results are
ignored by Git and must stay outside a public document root.

## Reproduce a preset

From the repository root (choose a new output filename on each run):

```sh
Services/visualizer/.venv/bin/python Services/visualizer/segment_image.py \
  --image Html/assets/images/rooms/luxury_living.webp \
  --prompts Services/visualizer/living-room-prompts.json \
  --checkpoint Services/visualizer/models/sam2.1_hiera_tiny.pt \
  --model tiny --device cpu --threads 4 \
  --output Services/visualizer/results/living-room.json
```

Runner limits: 12 MiB encoded input, 24 megapixels decoded, static JPEG/PNG/WebP,
at most eight surfaces and 64 click prompts per surface. EXIF orientation is
normalized and the photo is reduced to at most 1024 px per side by default.
The output contains the normalized photo and masks as PNG data URIs, model/hash,
prompt revision and timings. No external service/API key is required. Outputs
are atomically published without replacing an existing file, with mode 0600.

## Customer-photo local workflow

1. Serve `Html/` over local HTTP and open `visualizer.html`.
2. Import a room photo. Select Floor or Wall and add include/exclude points.
3. Download the source photo and selection prompts. Adjust four perspective
   corners separately; SAM does not calculate them.
4. Run the command above using those two downloaded files and a new result path.
5. Import that result through **Prepared SAM selection**. Review the mask, erase
   furniture, restore missing regions, choose materials, and save/export.

Changing materials does not rerun inference. Saved photo designs use IndexedDB;
browser-local designs can be lost if browser data is cleared. No photo goes to
the PHP backend in this slice. The prototype is intended for local HTTP;
`file://` can block preset fetches and canvas export.

## Verification

```sh
python3 -m unittest discover -s Services/visualizer/tests -v
```

For browser tests, serve `Html/` on `127.0.0.1:8080`, launch a disposable Chrome
profile with `--remote-debugging-port=9222`, and install `websocket-client==1.8.0`
in the test environment. Run `python Html/tests/photo_visualizer_browser.py`.
Tests add photo designs to that disposable browser profile; use no personal
profile. Headless Linux may need SwiftShader flags for WebGL. The browser tests
do not require SAM/model installation because preset masks are bundled.

## Evidence and limitations

2026-10-10 local CPU: SAM 2.1 tiny ran the synthetic living room in 4.952 seconds
inside the inference runner (model load, image encode, two mask decodes). On the
furnished room photograph, tiny took 5.056 s and small 5.427 s. These are single
warm-filesystem samples, not throughput guarantees. A further small-model prompt
revision measured 9.93 s total process wall time and 948,952 KiB peak RSS; process
startup/import overhead is material. No GPU benchmark or production hosting
capacity test has run.

Both models can leak into similarly patterned furniture or miss shadowed areas.
The synthetic preset has a recorded coffee-table exclusion in its manifest.
The sunlit photo uses revision-1 small masks; revision-2 `sunlit-room-evaluation-prompts.json` prompts are retained as
an evaluation example, and did not uniformly improve quality. Mask scores do not
prove correctness. Wider image/device evaluation, physical calibration, production
privacy/retention and queue integration remain pending.

Models/code originate from Meta's Apache 2.0 SAM 2 repository; we do not vendor
upstream code or model weights. Existing room/texture provenance is retained in
`Html/README.md`. The renderer/editor code here is original project code.
