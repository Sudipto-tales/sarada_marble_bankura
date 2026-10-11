#!/usr/bin/env python3
"""Bounded local SAM 2 photo segmentation. Never accepts remote URLs."""
import argparse
import base64
import hashlib
import io
import json
import math
import os
from pathlib import Path
import tempfile
import time

MODELS = {
    "tiny": "configs/sam2.1/sam2.1_hiera_t.yaml",
    "small": "configs/sam2.1/sam2.1_hiera_s.yaml",
}
MAX_BYTES = 12 * 1024 * 1024
MAX_PIXELS = 24_000_000


def pair(value):
    if (not isinstance(value, list) or len(value) != 2 or
        any(type(x) not in (int, float) or not math.isfinite(x) or not 0 <= x <= 1 for x in value)):
        raise ValueError("Coordinates must be finite normalized [x, y] pairs")
    return value


def validate_prompts(data):
    if not isinstance(data, dict) or data.get("version") != 1:
        raise ValueError("Expected prompts version 1")
    revision = data.get("revision")
    if type(revision) is not int or not 1 <= revision <= 1_000_000:
        raise ValueError("Invalid prompt revision")
    surfaces = data.get("surfaces")
    if not isinstance(surfaces, list) or not 1 <= len(surfaces) <= 8:
        raise ValueError("Supply 1–8 surfaces")
    ids = set()
    for surface in surfaces:
        if not isinstance(surface, dict):
            raise ValueError("Invalid surface")
        sid = surface.get("id", "")
        if not isinstance(sid, str) or not sid or len(sid) > 40 or not all(c.isascii() and (c.isalnum() or c in "_-") for c in sid) or sid in ids:
            raise ValueError("Surface IDs must be unique ASCII identifiers")
        ids.add(sid)
        if surface.get("label") not in ("floor", "wall"):
            raise ValueError("Label must be floor or wall")
        points = surface.get("points", [])
        if not isinstance(points, list) or len(points) > 64:
            raise ValueError("Too many prompts")
        for point in points:
            if not isinstance(point, dict) or type(point.get("label")) is not int or point["label"] not in (0, 1):
                raise ValueError("Point label must be 0 or 1")
            pair(point.get("xy"))
        box = surface.get("box")
        if box is not None:
            if not isinstance(box, list) or len(box) != 4:
                raise ValueError("Invalid box")
            pair(box[:2]); pair(box[2:])
            if box[0] >= box[2] or box[1] >= box[3]:
                raise ValueError("Box must have positive area")
        if box is None and not any(p["label"] == 1 for p in points):
            raise ValueError("A positive click or box is required")
        corners = surface.get("corners", [[0, 0], [1, 0], [1, 1], [0, 1]])
        if not isinstance(corners, list) or len(corners) != 4:
            raise ValueError("Expected four ordered corners")
        for corner in corners:
            pair(corner)
        crosses = []
        for i in range(4):
            a, b, c = corners[i], corners[(i+1) % 4], corners[(i+2) % 4]
            crosses.append((b[0]-a[0])*(c[1]-b[1])-(b[1]-a[1])*(c[0]-b[0]))
        if not (all(x > 1e-6 for x in crosses) or all(x < -1e-6 for x in crosses)):
            raise ValueError("Corners must form a convex nondegenerate quadrilateral")
    return data


def png_data(image):
    buf = io.BytesIO()
    image.save(buf, format="PNG")
    return buf.getvalue()


def uri(data):
    return "data:image/png;base64," + base64.b64encode(data).decode("ascii")


def run(args):
    if args.prompts.stat().st_size > 64 * 1024:
        raise ValueError("Prompt file exceeds 64 KiB")
    prompts = validate_prompts(json.loads(args.prompts.read_text()))
    if args.image.stat().st_size > MAX_BYTES:
        raise ValueError("Input exceeds 12 MiB")
    if not args.checkpoint.is_file():
        raise ValueError("Local checkpoint missing")
    if args.output.exists():
        raise ValueError("Output already exists; choose a new result path")

    from PIL import Image, ImageOps
    Image.MAX_IMAGE_PIXELS = MAX_PIXELS
    with Image.open(args.image) as source:
        if source.format not in ("PNG", "JPEG", "WEBP") or source.width * source.height > MAX_PIXELS or getattr(source, "n_frames", 1) != 1:
            raise ValueError("Use a static JPEG, PNG or WebP within 24 megapixels")
        image = ImageOps.exif_transpose(source).convert("RGB")
    image.thumbnail((args.max_side, args.max_side))
    width, height = image.size
    if min(width, height) < 32:
        raise ValueError("Image is too small")
    normalized = png_data(image)

    import numpy as np
    import torch
    from sam2.build_sam import build_sam2
    from sam2.sam2_image_predictor import SAM2ImagePredictor
    if args.device == "cuda" and not torch.cuda.is_available():
        raise ValueError("CUDA is unavailable")
    torch.set_num_threads(args.threads)
    started = time.perf_counter()
    model = build_sam2(MODELS[args.model], str(args.checkpoint.resolve()), device=args.device)
    # Disable optional CUDA connected-component postprocessing for portable CPU inference.
    predictor = SAM2ImagePredictor(model, max_hole_area=0, max_sprinkle_area=0)
    loaded = time.perf_counter()
    results = []
    with torch.inference_mode():
        predictor.set_image(np.array(image, copy=True))
        encoded = time.perf_counter()
        for surface in prompts["surfaces"]:
            points = surface.get("points", [])
            xy = np.array([[p["xy"][0] * (width-1), p["xy"][1] * (height-1)] for p in points], dtype=np.float32) if points else None
            labels = np.array([p["label"] for p in points], dtype=np.int32) if points else None
            box = np.array(surface["box"], dtype=np.float32) * np.array([width-1, height-1, width-1, height-1]) if "box" in surface else None
            masks, scores, _ = predictor.predict(point_coords=xy, point_labels=labels, box=box, multimask_output=True)
            candidates = [i for i in range(len(masks)) if masks[i].any() and np.isfinite(scores[i])]
            if not candidates:
                raise ValueError("SAM returned no usable mask")
            chosen = max(candidates, key=lambda i: scores[i])
            mask = Image.fromarray(masks[chosen].astype(np.uint8) * 255)
            results.append({"id": surface["id"], "label": surface["label"],
                            "mask": uri(png_data(mask)), "score": float(scores[chosen]),
                            "corners": surface.get("corners", [[0, 0], [1, 0], [1, 1], [0, 1]])})
    finished = time.perf_counter()
    with args.checkpoint.open("rb") as checkpoint_file:
        checkpoint_hash = hashlib.file_digest(checkpoint_file, "sha256").hexdigest()
    result = {"version": 1, "revision": prompts["revision"],
              "photo": {"dataUrl": uri(normalized), "sha256": hashlib.sha256(normalized).hexdigest(), "width": width, "height": height},
              "surfaces": results,
              "model": {"name": "sam2.1_hiera_" + args.model,
                        "checkpointSha256": checkpoint_hash},
              "metrics": {"device": args.device, "torch": torch.__version__, "threads": args.threads,
                          "loadSeconds": round(loaded-started, 3), "encodeSeconds": round(encoded-loaded, 3),
                          "decodeSeconds": round(finished-encoded, 3), "totalSeconds": round(finished-started, 3)}}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    # Exclusive publication prevents accidental overwrite of another revision.
    with tempfile.NamedTemporaryFile(mode="w", dir=args.output.parent, delete=False) as temp:
        temporary = Path(temp.name)
        try:
            os.chmod(temporary, 0o600)
            json.dump(result, temp, separators=(",", ":"), allow_nan=False)
            temp.flush(); os.fsync(temp.fileno())
            os.link(temporary, args.output)
        finally:
            temporary.unlink(missing_ok=True)
    print(json.dumps({"output": str(args.output), "metrics": result["metrics"],
                      "scores": {s["id"]: s["score"] for s in results}}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image", type=Path, required=True)
    parser.add_argument("--prompts", type=Path, required=True)
    parser.add_argument("--checkpoint", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--model", choices=MODELS, default="tiny")
    parser.add_argument("--device", choices=("cpu", "cuda"), default="cpu")
    parser.add_argument("--max-side", type=int, choices=(640, 1024, 1536), default=1024)
    parser.add_argument("--threads", type=int, choices=range(1, 17), default=4)
    args = parser.parse_args()
    try:
        run(args)
    except (ValueError, OSError, ImportError) as exc:
        parser.exit(1, f"Segmentation failed: {exc}\n")


if __name__ == "__main__":
    main()
