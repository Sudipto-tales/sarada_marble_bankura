"""Generates every bundled image asset for the Maa Sarada app.

Run:  /tmp/mvenv/bin/python tool/generate_assets.py
Everything is procedural / derived from the client logo — no network access.
"""
import os, sys, math, json
import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from marble_gen import MARBLES, MARBLE_BY_ID, marble_tile, fbm, _hex
import room_render as RR

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
A = os.path.join(ROOT, "assets")
LOGO_SRC = "/home/weloin/.claude/image-cache/b84bbf21-e935-4d4a-82aa-28c10fd6ca65/1.png"

INK = (6, 24, 38)
DEEP = (14, 108, 134)
TEAL = (43, 184, 204)
CYAN = (72, 208, 216)
ICE = (208, 240, 248)


def out(*p):
    q = os.path.join(A, *p)
    os.makedirs(os.path.dirname(q), exist_ok=True)
    return q


def src_out(*p):
    """Artwork the app never loads (store icon, master logos): kept in the repo
    for regeneration but deliberately outside assets/ so it stays out of the APK."""
    q = os.path.join(ROOT, "tool", "brand_src", *p)
    os.makedirs(os.path.dirname(q), exist_ok=True)
    return q


# Bundle size matters: every byte here ships inside the APK. Runtime assets are
# WebP — ~40% smaller than the equivalent JPEG and, unlike a palette-quantised
# PNG, it keeps a clean alpha channel for the logo lockups.
def save(img, path, q=82, alpha=False):
    if isinstance(img, np.ndarray):
        img = Image.fromarray(img)
    if path.endswith(".webp"):
        img = img.convert("RGBA" if alpha else "RGB")
        img.save(path, format="WEBP", quality=q, method=6)
    elif path.endswith(".jpg"):
        img.convert("RGB").save(path, quality=q, optimize=True,
                                progressive=True, subsampling=2)
    else:
        img.save(path, optimize=True)
    return path


# ============================================================== textures ====
TILE = 768
_cache = {}


def tex(mid):
    if mid not in _cache:
        _cache[mid] = marble_tile(TILE, MARBLE_BY_ID[mid])
    return _cache[mid]


def plaster(color, size=384, seed=7, strength=8):
    n = fbm(size, size, seed, octaves=5, base_res=4)
    base = np.array(color, np.float32)[None, None, :] + ((n - .5) * strength)[..., None]
    return np.clip(base, 0, 255).astype(np.uint8)


def gen_textures():
    for m in MARBLES:
        t = tex(m["id"])
        save(t, out("textures", "marble", m["id"] + ".webp"), 82)
        save(Image.fromarray(t).resize((256, 256), Image.LANCZOS),
             out("textures", "marble", m["id"] + "_thumb.webp"), 80)
    print("textures:", len(MARBLES))


# =============================================================== rooms ======
def _wall_tex():
    return plaster((233, 231, 226), seed=31, strength=7)


def _ceil_tex():
    return plaster((246, 246, 244), seed=44, strength=5)


def _wood_tex():
    n = fbm(256, 256, 91, octaves=6, base_res=3)
    g = np.sin((np.mgrid[0:256, 0:256][0] / 256 * 34 + n * 9)) * .5 + .5
    base = np.array([88, 62, 42], np.float32)[None, None, :] + (g * 34)[..., None]
    return np.clip(base, 0, 255).astype(np.uint8)


ROOMS = {
    "luxury_living": dict(
        name="Luxury Living Room", dim=(7.0, 3.4, 8.0),
        cam=dict(pos=(0.0, 1.52, -3.1), yaw=0, pitch=-11), fov=74,
        floor="statuario", back="bianco_dolomite", side="__wall", counter="calacatta_gold",
        windows=[dict(face="wall_left", rect=(-1.4, 2.2, 0.85, 2.55), color=(238, 248, 252))],
        furn="living"),
    "modern_kitchen": dict(
        name="Modern Kitchen", dim=(6.0, 3.2, 7.0),
        cam=dict(pos=(0.0, 1.55, -3.30), yaw=0, pitch=-12), fov=78,
        floor="grey_william", back="nero_marquina", side="__wall", counter="calacatta_gold",
        windows=[dict(face="wall_right", rect=(-0.8, 1.8, 1.05, 2.35), color=(240, 249, 252))],
        furn="kitchen"),
    "premium_bathroom": dict(
        name="Premium Bathroom", dim=(4.2, 3.0, 5.0),
        cam=dict(pos=(0.0, 1.50, -2.34), yaw=0, pitch=-11), fov=80,
        floor="carrara_white", back="calacatta_gold", side="__wall", counter="nero_marquina",
        windows=[dict(face="wall_left", rect=(-0.5, 1.3, 1.2, 2.3), color=(236, 247, 251))],
        furn="bath"),
    "luxury_bedroom": dict(
        name="Luxury Bedroom", dim=(6.4, 3.3, 7.4),
        cam=dict(pos=(0.0, 1.52, -2.9), yaw=0, pitch=-12), fov=74,
        floor="crema_marfil", back="emperador_dark", side="__wall", counter="botticino_beige",
        windows=[dict(face="wall_right", rect=(-1.2, 1.6, 1.0, 2.5), color=(244, 246, 250))],
        furn="bedroom"),
    "hotel_lobby": dict(
        name="Hotel Lobby", dim=(11.0, 4.6, 13.0),
        cam=dict(pos=(0.0, 1.62, -5.4), yaw=0, pitch=-9), fov=78,
        floor="nero_marquina", back="onyx_honey", side="__wall", counter="statuario",
        windows=[dict(face="wall_left", rect=(-3.0, 3.0, 1.0, 3.6), color=(240, 248, 252))],
        furn="lobby"),
    "modern_office": dict(
        name="Executive Office", dim=(7.2, 3.3, 8.2),
        cam=dict(pos=(0.0, 1.52, -3.2), yaw=0, pitch=-11), fov=74,
        floor="kashmir_white_granite", back="grey_william", side="__wall", counter="black_galaxy",
        windows=[dict(face="wall_right", rect=(-2.0, 2.2, 1.0, 2.6), color=(240, 248, 252))],
        furn="office"),
    "villa_interior": dict(
        name="Villa Interior", dim=(8.0, 4.0, 9.5),
        cam=dict(pos=(0.0, 1.58, -3.9), yaw=0, pitch=-11), fov=76,
        floor="botticino_beige", back="imperial_green", side="__wall", counter="crema_marfil",
        windows=[dict(face="wall_left", rect=(-2.2, 2.4, 1.0, 3.0), color=(242, 249, 252))],
        furn="living"),
}


def build_room(rid, overrides=None):
    cfg = dict(ROOMS[rid])
    o = overrides or {}
    w, h, d = cfg["dim"]
    lo = np.array([-w / 2, 0.0, -d / 2], np.float32)
    hi = np.array([w / 2, h, d / 2], np.float32)
    wall_t = _wall_tex()
    ftex = tex(o.get("floor", cfg["floor"]))
    btex = tex(o.get("back", cfg["back"]))
    side = o.get("side", cfg["side"])
    stex = wall_t if side == "__wall" else tex(side)
    ctex = tex(o.get("counter", cfg["counter"]))

    S = RR.Box
    surfaces = {
        "floor": S(lo, hi, ftex, tile=1.35),
        "ceil": S(lo, hi, _ceil_tex(), tile=2.0, tint=(1.02, 1.02, 1.02)),
        "wall_back": S(lo, hi, btex, tile=1.9),
        "wall_left": S(lo, hi, stex, tile=1.6),
        "wall_right": S(lo, hi, stex, tile=1.6),
        "wall_front": S(lo, hi, wall_t, tile=1.6),
    }
    room = dict(lo=lo, hi=hi, surfaces=surfaces)

    wood = _wood_tex()
    furn = []
    kind = cfg["furn"]
    bw = hi[0]                                  # half width, for wall-hugging props
    bd = hi[2]

    def rug(x0, z0, x1, z1, tint):
        return S((x0, 0.004, z0), (x1, 0.022, z1), None, tint=tint)

    def plant(x, z, h=1.35, scale=1.0):
        """Trunk plus a few offset leaf blocks so it silhouettes like foliage."""
        s = 0.30 * scale
        return [
            S((x - .22, 0, z - .22), (x + .22, .30, z + .22), None, tint=(0.19, 0.20, 0.22)),
            S((x - .04, .30, z - .04), (x + .04, h * .72, z + .04), None, tint=(0.24, 0.30, 0.22)),
            S((x - s, h * .60, z - s * .8), (x + s * .7, h * .84, z + s * .8), None, tint=(0.16, 0.34, 0.25)),
            S((x - s * .7, h * .78, z - s), (x + s, h, z + s * .7), None, tint=(0.20, 0.40, 0.29)),
        ]

    def lamp(x, z, h=1.62):
        return [
            S((x - .16, 0, z - .16), (x + .16, .04, z + .16), None, tint=(0.13, 0.14, 0.16)),
            S((x - .025, .04, z - .025), (x + .025, h - .26, z + .025), None, tint=(0.15, 0.16, 0.18)),
            S((x - .20, h - .26, z - .20), (x + .20, h, z + .20), None,
              tint=(0.96, 0.92, 0.84), emissive=(52, 44, 30)),
        ]

    def pendant(x, z, y_top, drop=1.15, r=0.16):
        return [
            S((x - .02, y_top - drop, z - .02), (x + .02, y_top, z + .02), None, tint=(0.14, 0.15, 0.17)),
            S((x - r, y_top - drop - .18, z - r), (x + r, y_top - drop, z + r), None,
              tint=(0.98, 0.94, 0.86), emissive=(70, 60, 42)),
        ]

    def art(x, y, w_, h_, z, tint):
        return [
            S((x - w_ / 2 - .04, y - .04, z - .06), (x + w_ / 2 + .04, y + h_ + .04, z - .02),
              None, tint=(0.12, 0.13, 0.15)),
            S((x - w_ / 2, y, z - .055), (x + w_ / 2, y + h_, z - .045), None, tint=tint),
        ]

    if kind == "living":
        furn += [
            rug(-1.9, 1.15, 1.9, 2.55, (0.27, 0.29, 0.32)),
            S((-2.0, 0.10, 1.4), (2.0, 0.46, 2.5), None, tint=(0.26, 0.29, 0.33)),   # sofa base
            S((-2.0, 0.46, 2.12), (2.0, 1.06, 2.5), None, tint=(0.32, 0.35, 0.39)),  # back
            S((-2.0, 0.46, 1.4), (-1.62, 0.86, 2.5), None, tint=(0.30, 0.33, 0.37)),  # arm L
            S((1.62, 0.46, 1.4), (2.0, 0.86, 2.5), None, tint=(0.30, 0.33, 0.37)),    # arm R
            S((-1.45, 0.46, 1.86), (-0.85, 0.72, 2.14), None, tint=(0.52, 0.44, 0.36)),
            S((0.85, 0.46, 1.86), (1.45, 0.72, 2.14), None, tint=(0.46, 0.50, 0.52)),
            S((-1.1, 0.02, -0.6), (1.1, 0.36, 0.5), ctex, tile=1.0),                  # marble table
            S((-0.30, 0.36, -0.15), (0.30, 0.44, 0.12), None, tint=(0.70, 0.66, 0.58)),
            S((-bw + .16, 0.0, -bd + .55), (-bw + .52, 0.62, bd * .05), wood, tile=1.0),
        ]
        furn += plant(bw - 0.85, bd - 1.1)
        furn += lamp(-bw + 0.95, bd - 1.35)
        furn += art(0.0, 1.42, 1.5, 0.95, bd, (0.42, 0.62, 0.68))
    if kind == "office":
        furn += [
            rug(-1.7, 0.15, 1.7, 1.55, (0.22, 0.24, 0.27)),
            S((-1.5, 0.70, 0.2), (1.5, 0.78, 1.5), ctex, tile=1.0),                   # desk top
            S((-1.45, 0.0, 0.25), (-1.25, 0.70, 1.45), None, tint=(0.15, 0.16, 0.18)),
            S((1.25, 0.0, 0.25), (1.45, 0.70, 1.45), None, tint=(0.15, 0.16, 0.18)),
            S((-0.45, 0.80, 1.10), (0.45, 1.34, 1.16), None, tint=(0.10, 0.11, 0.13)),  # monitor
            S((-0.06, 0.78, 1.16), (0.06, 0.86, 1.30), None, tint=(0.14, 0.15, 0.17)),
            S((-0.42, 0.0, -0.55), (0.42, 0.46, 0.15), None, tint=(0.17, 0.19, 0.22)),  # chair
            S((-0.42, 0.46, -0.55), (0.42, 1.20, -0.40), None, tint=(0.19, 0.21, 0.24)),
            S((bw - .52, 0.0, -0.4), (bw - .16, 2.05, 1.6), wood, tile=1.1),            # shelving
        ]
        furn += plant(-bw + 0.85, bd - 1.4)
        furn += art(0.0, 1.58, 1.7, 0.90, bd, (0.36, 0.55, 0.62))
    if kind == "kitchen":
        furn += [
            S((-2.5, 0.0, 1.2), (2.5, 0.88, 1.9), wood, tile=1.2),
            S((-2.5, 0.88, 1.15), (2.5, 0.95, 1.95), ctex, tile=1.1),        # counter top
            S((-1.4, 0.0, -0.9), (1.4, 0.86, 0.1), wood, tile=1.2),          # island
            S((-1.5, 0.86, -0.95), (1.5, 0.94, 0.15), ctex, tile=1.1),
            S((-2.4, 1.75, 1.55), (2.4, 2.45, 1.92), None, tint=(0.20, 0.23, 0.26)),
            S((-0.34, 0.94, -0.62), (0.34, 1.02, -0.20), None, tint=(0.62, 0.58, 0.52)),
            S((0.75, 0.94, -0.60), (0.95, 1.22, -0.40), None, tint=(0.30, 0.42, 0.34)),
        ]
        for sx_ in (-0.85, 0.0, 0.85):
            furn += [S((sx_ - .19, 0.0, -1.70), (sx_ + .19, 0.66, -1.32), None, tint=(0.17, 0.19, 0.21)),
                     S((sx_ - .19, 0.66, -1.70), (sx_ + .19, 1.02, -1.62), None, tint=(0.20, 0.22, 0.25))]
        for px_ in (-1.0, 0.0, 1.0):
            furn += pendant(px_, -0.4, hi[1], drop=1.05, r=0.14)
    if kind == "bath":
        furn += [
            S((-1.7, 0.0, 0.9), (0.2, 0.58, 1.9), None, tint=(0.95, 0.96, 0.96)),  # tub
            S((-1.66, 0.14, 0.94), (0.16, 0.60, 1.86), ctex, tile=0.7),      # tub skirt band
            S((-1.60, 0.50, 1.00), (0.10, 0.58, 1.80), None, tint=(0.74, 0.86, 0.92)),  # water
            S((-1.9, 0.90, bd - .02), (-1.2, 1.02, bd - .01), None, tint=(0.86, 0.87, 0.86)),  # towel rail
            S((-1.82, 0.62, bd - .06), (-1.28, 0.98, bd - .02), None, tint=(0.92, 0.92, 0.90)),  # towel
            S((0.7, 0.0, 1.2), (1.8, 0.82, 1.8), wood, tile=1.0),
            S((0.65, 0.82, 1.15), (1.85, 0.90, 1.85), ctex, tile=0.9),       # vanity top
            S((1.05, 0.90, 1.35), (1.45, 1.02, 1.68), None, tint=(0.90, 0.93, 0.95)),  # basin
            S((0.80, 0.94, bd - .02), (1.70, 1.86, bd - .01), None, tint=(0.78, 0.86, 0.90)),  # mirror
            S((-1.9, 0.0, -1.5), (-1.4, 0.06, -0.7), None, tint=(0.72, 0.74, 0.74)),  # mat
        ]
        furn += plant(1.55, -1.35, h=0.95, scale=0.7)
    if kind == "bedroom":
        furn += [
            rug(-2.3, 0.35, 2.3, 1.05, (0.25, 0.23, 0.22)),
            S((-1.7, 0.10, 0.6), (1.7, 0.52, 2.7), None, tint=(0.28, 0.25, 0.23)),
            S((-1.7, 0.52, 0.6), (1.7, 0.74, 2.6), None, tint=(0.88, 0.86, 0.82)),
            S((-1.7, 0.74, 2.05), (-0.15, 0.92, 2.5), None, tint=(0.94, 0.93, 0.90)),
            S((0.15, 0.74, 2.05), (1.7, 0.92, 2.5), None, tint=(0.94, 0.93, 0.90)),
            S((-1.72, 0.74, 1.30), (1.72, 0.80, 2.05), None, tint=(0.36, 0.34, 0.36)),
            S((-1.78, 0.74, 2.62), (1.78, 1.55, 2.74), None, tint=(0.34, 0.30, 0.28)),  # headboard
            S((-2.5, 0.0, 1.9), (-1.9, 0.52, 2.5), ctex, tile=0.8),
            S((1.9, 0.0, 1.9), (2.5, 0.52, 2.5), ctex, tile=0.8),
        ]
        furn += lamp(-2.2, 2.2, h=0.98)
        furn += lamp(2.2, 2.2, h=0.98)
        furn += plant(bw - 0.8, -0.9)
    if kind == "lobby":
        for cx in (-3.2, 3.2):
            furn.append(S((cx - .38, 0, -1.2), (cx + .38, hi[1], -.44), ctex, tile=1.4))
            furn.append(S((cx - .38, 0, 2.4), (cx + .38, hi[1], 3.16), ctex, tile=1.4))
        furn += [
            S((-2.4, 0.0, 3.6), (2.4, 1.12, 4.5), ctex, tile=1.3),           # reception desk
            S((-2.5, 1.12, 3.55), (2.5, 1.20, 4.55), None, tint=(0.16, 0.17, 0.19)),
            rug(-2.6, 3.25, 2.6, 4.6, (0.20, 0.22, 0.25)),
            S((-3.0, 0.08, -0.4), (-1.4, 0.44, 1.2), None, tint=(0.24, 0.27, 0.31)),
            S((-3.0, 0.44, 0.85), (-1.4, 1.00, 1.2), None, tint=(0.28, 0.31, 0.35)),
            S((1.4, 0.08, -0.4), (3.0, 0.44, 1.2), None, tint=(0.24, 0.27, 0.31)),
            S((1.4, 0.44, 0.85), (3.0, 1.00, 1.2), None, tint=(0.28, 0.31, 0.35)),
            S((-0.7, 0.02, 0.0), (0.7, 0.42, 0.8), ctex, tile=1.0),
        ]
        for px_ in (-2.2, 0.0, 2.2):
            furn += pendant(px_, 0.4, hi[1], drop=1.9, r=0.22)
        furn += plant(-bw + 1.1, bd - 2.2, h=1.9, scale=1.25)
        furn += plant(bw - 1.1, bd - 2.2, h=1.9, scale=1.25)
    return room, furn, cfg


def render_room(rid, size=(1000, 700), overrides=None, cam=None, fov=None):
    room, furn, cfg = build_room(rid, overrides)
    c = dict(cfg["cam"]); c.update(cam or {})
    return RR.render(size, c, room, furn, cfg["windows"], fov or cfg["fov"],
                     reflectivity=cfg.get("refl", 0.44))


def gen_rooms():
    for rid in ROOMS:
        img = render_room(rid, (1000, 640))
        save(img, out("images", "rooms", rid + ".webp"), 82)
        save(Image.fromarray(img).resize((480, 307), Image.LANCZOS),
             out("images", "rooms", rid + "_thumb.webp"), 80)
    print("rooms:", len(ROOMS))


# ============================================================ products ======
def slab_render(mid, size=(1000, 750)):
    """Studio shot: a polished slab standing on a dark premium backdrop."""
    W, H = size
    t = Image.fromarray(tex(mid)).resize((int(W * .78), int(W * .78)), Image.LANCZOS)
    bg = Image.new("RGB", (W, H), (18, 22, 28))
    dr = ImageDraw.Draw(bg)
    for y in range(H):
        k = y / H
        dr.line([(0, y), (W, y)], fill=(int(16 + 26 * k), int(26 + 34 * k), int(34 + 40 * k)))
    sw, sh = int(W * .62), int(H * .70)
    slab = t.resize((sw, sh), Image.LANCZOS)
    # bevel highlight
    ov = Image.new("L", (sw, sh), 0)
    od = ImageDraw.Draw(ov)
    od.rectangle([0, 0, sw - 1, sh - 1], outline=90, width=3)
    slab = Image.composite(Image.new("RGB", (sw, sh), (255, 255, 255)), slab, ov.point(lambda v: v // 2))
    x0, y0 = (W - sw) // 2, int(H * .10)
    shadow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(shadow).rectangle([x0 + 14, y0 + 22, x0 + sw + 16, y0 + sh + 26], fill=(0, 0, 0, 150))
    shadow = shadow.filter(ImageFilter.GaussianBlur(22))
    bg = Image.alpha_composite(bg.convert("RGBA"), shadow).convert("RGB")
    bg.paste(slab, (x0, y0))
    # reflection
    refl = slab.transpose(Image.FLIP_TOP_BOTTOM).crop((0, 0, sw, int(sh * .22)))
    refl = refl.point(lambda v: int(v * .28))
    bg.paste(refl, (x0, y0 + sh + 4))
    return bg


def macro_render(mid, size=(768, 768)):
    t = Image.fromarray(tex(mid)).resize((int(size[0] * 2.2), int(size[1] * 2.2)), Image.LANCZOS)
    c = t.crop((int(size[0] * .5), int(size[1] * .5), int(size[0] * 1.5), int(size[1] * 1.5)))
    c = c.resize(size, Image.LANCZOS).filter(ImageFilter.UnsharpMask(2, 110, 3))
    g = Image.new("L", size, 0)
    d = ImageDraw.Draw(g)
    for i in range(60):
        d.rectangle([i, i, size[0] - i, size[1] - i], outline=int(i * 1.6))
    return Image.composite(Image.new("RGB", size, (10, 16, 22)), c, g.filter(ImageFilter.GaussianBlur(30)))


ROOM_FOR_COLOR = {
    "White": "luxury_living", "Grey": "modern_office", "Black": "modern_kitchen",
    "Beige": "luxury_bedroom", "Brown": "luxury_bedroom", "Green": "villa_interior",
    "Gold": "hotel_lobby", "Pink": "premium_bathroom",
}


def gen_products(product_rooms):
    for m in MARBLES:
        mid = m["id"]
        save(slab_render(mid), out("images", "products", f"{mid}_main.webp"), 82)
        save(macro_render(mid), out("images", "products", f"{mid}_macro.webp"), 82)
        t = Image.fromarray(tex(mid)).resize((768, 768), Image.LANCZOS)
        save(t, out("images", "products", f"{mid}_tile.webp"), 82)
        rid = product_rooms.get(mid, "luxury_living")
        img = render_room(rid, (960, 640), overrides=dict(floor=mid))
        save(img, out("images", "products", f"{mid}_room.webp"), 82)
    print("products:", len(MARBLES) * 4, "images")


# =========================================================== categories =====
CATEGORY_TEX = {
    "white_marble": "statuario", "black_marble": "nero_marquina",
    "beige_marble": "crema_marfil", "green_marble": "imperial_green",
    "italian_marble": "calacatta_gold", "indian_marble": "makrana_white",
    "premium_marble": "onyx_honey", "granite": "black_galaxy",
}


def gen_categories():
    for cid, mid in CATEGORY_TEX.items():
        t = Image.fromarray(tex(mid)).resize((600, 600), Image.LANCZOS)
        ov = Image.new("RGBA", (600, 600), (0, 0, 0, 0))
        d = ImageDraw.Draw(ov)
        for y in range(600):
            d.line([(0, y), (600, y)], fill=(6, 24, 38, int(120 * (y / 600) ** 2)))
        img = Image.alpha_composite(t.convert("RGBA"), ov).convert("RGB")
        save(img, out("images", "categories", cid + ".webp"), 82)
    print("categories:", len(CATEGORY_TEX))


# ============================================================== banners =====
BANNERS = [
    ("hero_1", "luxury_living", dict(floor="calacatta_gold", back="statuario"), dict(yaw=-16, pitch=-8)),
    ("hero_2", "hotel_lobby", dict(floor="nero_marquina"), dict(yaw=12, pitch=-3)),
    ("hero_3", "modern_kitchen", dict(floor="grey_william"), dict(yaw=-8, pitch=-11)),
    ("hero_4", "premium_bathroom", dict(floor="carrara_white"), dict(yaw=10, pitch=-6)),
    ("hero_5", "villa_interior", dict(floor="botticino_beige"), dict(yaw=-14, pitch=-5)),
]
INSPIRATION = [
    ("insp_living", "luxury_living", {}), ("insp_kitchen", "modern_kitchen", {}),
    ("insp_bath", "premium_bathroom", {}), ("insp_bedroom", "luxury_bedroom", {}),
    ("insp_lobby", "hotel_lobby", {}), ("insp_office", "modern_office", {}),
    ("insp_villa", "villa_interior", {}),
]


def gen_banners():
    for name, rid, ov, cam in BANNERS:
        img = render_room(rid, (1200, 620), overrides=ov, cam=cam)
        save(img, out("images", "banners", name + ".webp"), 82)
    for name, rid, ov in INSPIRATION:
        img = render_room(rid, (820, 560), overrides=ov, cam=dict(yaw=6))
        save(img, out("images", "inspiration", name + ".webp"), 82)
    # offer strips
    for i, (mid, c1) in enumerate([("calacatta_gold", DEEP), ("nero_marquina", INK),
                                   ("imperial_green", DEEP), ("onyx_honey", INK),
                                   ("statuario", DEEP)], 1):
        t = Image.fromarray(tex(mid)).resize((1000, 420), Image.LANCZOS)
        ov = Image.new("RGBA", (1000, 420), (0, 0, 0, 0))
        d = ImageDraw.Draw(ov)
        for x in range(1000):
            d.line([(x, 0), (x, 420)], fill=(*c1, int(210 * (1 - x / 1000) ** 1.2)))
        save(Image.alpha_composite(t.convert("RGBA"), ov).convert("RGB"),
             out("images", "banners", f"offer_{i}.webp"), 84)
    print("banners + inspiration + offers done")


# ================================================================ brand =====
def _key_logo(box):
    """Alpha-key the logo off the grey wall mockup background."""
    im = Image.open(LOGO_SRC).convert("RGB")
    a = np.asarray(im).astype(np.float32)
    sat = a.max(2) - a.min(2)
    lum = a.mean(2)
    satm = np.clip((sat - 22.0) / 24.0, 0, 1)
    # only trust dark pixels that sit inside/next to the coloured emblem
    core = Image.fromarray(((sat > 30).astype(np.uint8) * 255))
    region = np.asarray(core.filter(ImageFilter.MaxFilter(9))
                            .filter(ImageFilter.GaussianBlur(2.5))).astype(np.float32) / 255.0
    darkm = np.clip((70.0 - lum) / 28.0, 0, 1) * np.clip(region * 1.6, 0, 1)
    alpha = np.clip(np.maximum(satm, darkm), 0, 1)
    alpha = np.where(alpha < 0.22, 0.0, alpha)
    al = Image.fromarray((alpha * 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(0.6))
    rgba = im.convert("RGBA")
    rgba.putalpha(al)
    crop = rgba.crop(box)
    bb = crop.getchannel("A").point(lambda v: 255 if v > 55 else 0).getbbox()
    return crop.crop(bb) if bb else crop


def _emblem():
    return _key_logo((150, 10, 425, 235))


def _wordmark():
    return _key_logo((110, 236, 460, 322))


def brand_bg(size, radial=True):
    W, H = size
    yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
    r = np.sqrt(((xx / W - .5) * 2) ** 2 + ((yy / H - .55) * 2) ** 2)
    k = np.clip(1 - r * .78, 0, 1)[..., None] if radial else (1 - yy / H)[..., None]
    c = np.array(INK, np.float32)[None, None, :] * (1 - k) + np.array(DEEP, np.float32)[None, None, :] * k
    n = fbm(H, W, 555, octaves=5, base_res=3)
    c += ((n - .5) * 9)[..., None]
    return Image.fromarray(np.clip(c, 0, 255).astype(np.uint8))


def gen_brand():
    em = _emblem()
    wm = _wordmark()
    # Runtime asset: displayed at 132 px at most, so 264 px covers 2x screens.
    save(em.resize((264, int(em.height * 264 / em.width)), Image.LANCZOS),
         out("brand", "logo_mark.webp"), 92, alpha=True)
    # Not loaded by the app — kept as source artwork outside the bundle.
    wm.save(src_out("logo_wordmark.png"))
    # full lockup on transparent
    W = 900
    ew = int(W * .62)
    e2 = em.resize((ew, int(em.height * ew / em.width)), Image.LANCZOS)
    ww = int(W * .80)
    w2 = wm.resize((ww, int(wm.height * ww / wm.width)), Image.LANCZOS)
    Hh = e2.height + w2.height + 40
    full = Image.new("RGBA", (W, Hh), (0, 0, 0, 0))
    full.alpha_composite(e2, ((W - ew) // 2, 0))
    full.alpha_composite(w2, ((W - ww) // 2, e2.height + 32))
    # Shown at ~200 px wide; 560 px is generous for 3x screens.
    save(full.resize((560, int(full.height * 560 / W)), Image.LANCZOS),
         out("brand", "logo_full.webp"), 92, alpha=True)
    full.save(src_out("logo_full_master.png"))
    # splash lockup on brand bg
    sp = brand_bg((1080, 1080))
    spc = sp.convert("RGBA")
    fw = 760
    f2 = full.resize((fw, int(full.height * fw / full.width)), Image.LANCZOS)
    spc.alpha_composite(f2, ((1080 - fw) // 2, (1080 - f2.height) // 2))
    save(spc.convert("RGB"), out("brand", "splash.webp"), 86)
    print("brand assets done")
    return em, full


# =============================================================== icons ======
def rounded_icon(em, size, pad=0.17, radius_ratio=0.225, transparent=False, scale=1.0):
    S = size
    if transparent:
        base = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    else:
        bg = brand_bg((S, S)).convert("RGBA")
        mask = Image.new("L", (S, S), 0)
        ImageDraw.Draw(mask).rounded_rectangle([0, 0, S - 1, S - 1], radius=int(S * radius_ratio), fill=255)
        base = Image.new("RGBA", (S, S), (0, 0, 0, 0))
        base.paste(bg, (0, 0), mask)
    ew = int(S * (1 - pad * 2) * scale)
    e2 = em.resize((ew, int(em.height * ew / em.width)), Image.LANCZOS)
    glow = e2.getchannel("A").filter(ImageFilter.GaussianBlur(S * .02))
    gl = Image.new("RGBA", e2.size, (*CYAN, 90))
    gl.putalpha(glow.point(lambda v: int(v * .55)))
    x = (S - ew) // 2
    y = (S - e2.height) // 2
    base.alpha_composite(gl, (x, y))
    base.alpha_composite(e2, (x, y))
    return base


ANDROID_MIP = {"mdpi": 48, "hdpi": 72, "xhdpi": 96, "xxhdpi": 144, "xxxhdpi": 192}
IOS_ICONS = [(20, [1, 2, 3]), (29, [1, 2, 3]), (40, [1, 2, 3]), (60, [2, 3]),
             (76, [1, 2]), (83.5, [2]), (1024, [1])]


def gen_icons(em):
    master = rounded_icon(em, 1024)
    master.convert("RGB").save(src_out("app_icon.png"))
    # ---- android
    for d, s in ANDROID_MIP.items():
        p = os.path.join(ROOT, "android/app/src/main/res", f"mipmap-{d}")
        os.makedirs(p, exist_ok=True)
        rounded_icon(em, s).convert("RGB").save(os.path.join(p, "ic_launcher.png"))
        # adaptive foreground (safe zone 66%)
        fg = rounded_icon(em, int(s * 2.2), transparent=True, pad=0.26, scale=0.86)
        fg.save(os.path.join(p, "ic_launcher_foreground.png"))
    res = os.path.join(ROOT, "android/app/src/main/res")
    os.makedirs(os.path.join(res, "values"), exist_ok=True)
    os.makedirs(os.path.join(res, "mipmap-anydpi-v26"), exist_ok=True)
    with open(os.path.join(res, "mipmap-anydpi-v26", "ic_launcher.xml"), "w") as f:
        f.write('<?xml version="1.0" encoding="utf-8"?>\n'
                '<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">\n'
                '    <background android:drawable="@color/ic_launcher_background"/>\n'
                '    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>\n'
                '</adaptive-icon>\n')
    with open(os.path.join(res, "values", "colors.xml"), "w") as f:
        f.write('<?xml version="1.0" encoding="utf-8"?>\n<resources>\n'
                '    <color name="ic_launcher_background">#061826</color>\n</resources>\n')
    # ---- ios
    ip = os.path.join(ROOT, "ios/Runner/Assets.xcassets/AppIcon.appiconset")
    if os.path.isdir(ip):
        for base, scales in IOS_ICONS:
            for sc in scales:
                px = int(round(base * sc))
                nm = f"Icon-App-{base:g}x{base:g}@{sc}x.png"
                rounded_icon(em, px, radius_ratio=0.0).convert("RGB").save(os.path.join(ip, nm))
    # ---- web
    wp = os.path.join(ROOT, "web")
    if os.path.isdir(wp):
        os.makedirs(os.path.join(wp, "icons"), exist_ok=True)
        for s, nm in [(192, "Icon-192.png"), (512, "Icon-512.png")]:
            rounded_icon(em, s).convert("RGB").save(os.path.join(wp, "icons", nm))
            rounded_icon(em, s, transparent=False).convert("RGB").save(
                os.path.join(wp, "icons", nm.replace("Icon-", "Icon-maskable-")))
        rounded_icon(em, 64).convert("RGB").save(os.path.join(wp, "favicon.png"))
    print("icons done")


# ================================================================ main ======
def main():
    only = sys.argv[1] if len(sys.argv) > 1 else "all"
    if only in ("all", "textures"):
        gen_textures()
    if only in ("all", "brand", "icons"):
        em, _ = gen_brand()
        gen_icons(em)
    if only in ("all", "rooms"):
        gen_rooms()
    if only in ("all", "categories"):
        gen_categories()
    if only in ("all", "banners"):
        gen_banners()
    if only in ("all", "products"):
        prod_rooms = json.load(open(os.path.join(ROOT, "tool", "product_rooms.json"))) \
            if os.path.exists(os.path.join(ROOT, "tool", "product_rooms.json")) else {}
        gen_products(prod_rooms)
    print("DONE")


if __name__ == "__main__":
    main()
