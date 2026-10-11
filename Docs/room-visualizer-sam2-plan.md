# SAM 2 photo visualizer delivery

Authorized 2026-10-10. This extends the existing room visualizer plan for the
website; Flutter stays offline. Task status/evidence lives in
[the tracker](commerce/tracking.md). Source development is on `main`; no deploy.

## Delivery order and acceptance

- **SAM-01:** pinned local Python/SAM 2 runner, validated normalized click/box
  prompts, EXIF normalization, bounded image dimensions, binary PNG masks and
  versioned results. Run real inference and record model/runtime/latency; compare
  small/tiny and representative rooms before a production model decision.
- **SAM-02:** photo renderer in the existing HTML visualizer. Begin with one
  annotated preset; independent floor/wall assignments, projective texture
  mapping, foreground exclusion, rotation/scale/grout/shading, original toggle,
  correction tools, export and exact local design restoration. Other rooms use
  manual marking until annotated. Generated masks are proposals needing review.
- **SAM-03:** private normalized uploads, ownership, transactional processing
  requests and durable enqueue, polling, retention/deletion. Extend the existing
  `Website/app/Services/QueueService.php`, `app/Jobs/JobRegistry.php` and queue
  worker contracts only after benchmarking runtime budgets. Existing 15-second
  job budgets and email-only enabled queues do not automatically support AI.
- **SAM-04:** connect positive/negative clicks and revisions to the backend;
  bounded CLI workers invoke the runner, fence results with lease/revision
  checks, retry transient failures, allow manual correction on AI failure.
- **SAM-05:** integrate `Website/app/page/storefront/visualizer.php`, a Vayu
  bridge/controller, owned versioned designs and catalog textures. No frontend
  demo price/stock data becomes authoritative commerce data.
- **SAM-06:** MySQL concurrency/lease tests, cross-owner access tests, deletion,
  image-decoder abuse limits, browser/device performance and Developer regression.
  Hosting/runtime and GPU feasibility are explicit release gates.

## SAM-01/02 implementation guide

Inspected sources: `Html/storefront.js::visualizer` and `savedDesigns`,
`Html/visualizer.html`, `Html/storefront-data.js`, bundled `assets/textures/marble`,
and `Mobile/lib/features/visualization/photo/photo_room_viewport.dart`.
The existing website uses a CSS overlay and stores only room/product/surface;
new v2 designs must preserve both surfaces, masks and perspective. Retain old
saved entries and their reopen links.

SAM 2 is promptable segmentation, not a floor/wall classifier, a depth estimator
or 3D reconstruction. User labels identify the intended surface. Each planar
wall has its own mask and homography. Negative clicks and a manual erase brush
exclude furniture. Never infer room measurements from a mask.

### Local interface

`Services/visualizer/segment_image.py` accepts an input photo, a local checkpoint,
an approved model name, a prompts JSON and an output directory. Coordinates are
normalized to the EXIF-oriented source image. Output uses the same normalized
photo and one binary PNG per surface. A versioned manifest binds masks to the
photo SHA-256, dimensions, prompt revision, model/checkpoint hash and inference
metrics. No automatic model download or remote photo upload occurs in the runner.

Use `SAM2ImagePredictor` with one encoded image and per-surface point/box prompts.
Do not enable automatic full-image mask generation for the initial editor.
Choose the highest scored nonempty candidate; quality scores are not correctness
guarantees. Keep checkpoints, environments and user photos outside public paths
and Git. Input size/decoded-pixel limits apply before model loading.

### Rendering contract

Each surface stores stable ID, user label, mask, four ordered normalized plane
corners, texture/product reference, visual repeat count, rotation, grout and
shading strength. A mask defines visibility; plane corners define perspective.
Corners can extend outside the visible mask (for furniture occlusion). Reject
crossed, concave or degenerate quadrilaterals. Visual repeat is not physical
measurement; later optional known-length calibration uses explicit units.

Project a repeated texture field with a homography, clip its alpha by the mask,
then conservatively modulate source illumination. Preserve pixels outside masks
exactly. Original material patterns can leak through a shading approximation;
expose shading strength rather than promising recovered physical lighting.

Local imported photos/designs use IndexedDB instead of localStorage image blobs;
existing simple combinations stay compatible. Asynchronous image loads and SAM
results need revisions so older work never overwrites current selections.

## Backend contracts for SAM-03 onward

Proposed `visualizer_assets`, `visualizer_requests`, `visualizer_surfaces`,
`visualizer_designs`: owner/session hash, opaque asset references, expiry,
normalized coordinates, surface/prompt/result revisions and schema versions.
Store media privately, not in job payloads. Request creation and enqueue share a
PDO transaction. Owner comes from commerce auth or server guest session, never
from a client-supplied user ID. Cookie mutations require CSRF.

Proposed API prefix `/api/v1/visualizer`: asset upload/read/delete, segmentation
create/status, design create/read/update/delete. Poll with bounded backoff.
Workers are cron/CLI batches, not daemons; no mandatory Redis. Fenced atomic
result publication, bounded retries, per-owner quotas and private cleanup jobs
are required. Cross-machine processing needs a separately validated authenticated
transport; do not expose an unauthenticated Python service.

## Upstream and assets

- SAM 2: https://github.com/facebookresearch/sam2 (Apache 2.0 main model/code;
  preserve upstream and third-party notices when redistributing).
- Pin upstream commit `2b90b9f5ceec907a1c18123530e92e794ad901a4` for the feasibility
  environment. Dependency versions and checkpoint hashes belong in evidence.
- Existing room photos/textures retain their repository provenance in
  `Html/README.md`; do not introduce new redistribution claims.

No production hosting, model quality or performance claim follows from a CPU
smoke run. Record actual outcomes and remaining gates after each slice.
