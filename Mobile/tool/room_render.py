"""Offline ray-traced interior renderer.

Renders a cuboid room (floor / ceiling / 4 walls) plus axis-aligned furniture
boxes, sampling marble textures with real perspective. The Dart runtime renderer
uses the same room geometry description, so pre-rendered thumbnails and the live
3D room stay visually consistent.

Second pass adds a mirror bounce off the floor so polished marble actually
reflects the room, which is most of what makes a stone floor read as premium.
"""
import numpy as np

FLOOR_N = np.array([0.0, 1.0, 0.0], np.float32)


class Box:
    def __init__(self, lo, hi, tex, tile=1.0, tint=(1, 1, 1), emissive=None):
        self.lo = np.array(lo, np.float32)
        self.hi = np.array(hi, np.float32)
        self.tex = tex          # HxWx3 uint8 or None
        self.tile = tile        # metres per texture repeat
        self.tint = np.array(tint, np.float32)
        self.emissive = emissive


def _sample(tex, u, v, tile):
    h, w = tex.shape[:2]
    xi = (np.mod(u / tile, 1.0) * w).astype(np.int32) % w
    yi = (np.mod(v / tile, 1.0) * h).astype(np.int32) % h
    return tex[yi, xi].astype(np.float32)


FACES = [
    ("floor",      1, "lo", (0, 2), (0, 1, 0)),
    ("ceil",       1, "hi", (0, 2), (0, -1, 0)),
    ("wall_back",  2, "hi", (0, 1), (0, 0, -1)),
    ("wall_front", 2, "lo", (0, 1), (0, 0, 1)),
    ("wall_left",  0, "lo", (2, 1), (1, 0, 0)),
    ("wall_right", 0, "hi", (2, 1), (-1, 0, 0)),
]


def _trace(O, d, room, furniture, windows):
    """Trace rays. O and d are (...,3). Returns color, normal, t, floor mask."""
    shape = d.shape[:-1]
    lo, hi = room["lo"], room["hi"]
    best_t = np.full(shape, 1e9, np.float32)
    color = np.zeros(shape + (3,), np.float32)
    normal = np.zeros(shape + (3,), np.float32)
    is_floor = np.zeros(shape, bool)
    is_wall = np.zeros(shape, bool)
    hit_y = np.zeros(shape, np.float32)

    for name, axis, side, (ua, va), nrm in FACES:
        surf = room["surfaces"].get(name)
        if surf is None:
            continue
        plane = (lo if side == "lo" else hi)[axis]
        with np.errstate(divide="ignore", invalid="ignore"):
            t = (plane - O[..., axis]) / d[..., axis]
        t = np.nan_to_num(t, nan=-1.0, posinf=-1.0, neginf=-1.0)
        hitp = O + d * t[..., None]
        inside = (t > 0.02) & (t < best_t)
        for a in range(3):
            if a == axis:
                continue
            inside &= (hitp[..., a] >= lo[a] - 1e-3) & (hitp[..., a] <= hi[a] + 1e-3)
        if not inside.any():
            continue
        u = hitp[..., ua]
        v = hitp[..., va]
        c = _sample(surf.tex, u, v, surf.tile) * surf.tint

        # window cut-outs punched into this face
        for win in windows:
            if win["face"] != name:
                continue
            wu0, wu1, wv0, wv1 = win["rect"]
            m = (u >= wu0) & (u <= wu1) & (v >= wv0) & (v <= wv1)
            if m.any():
                glow = np.array(win.get("color", (245, 250, 252)), np.float32)
                grad = np.clip((v - wv0) / max(wv1 - wv0, 1e-3), 0, 1)
                gc = glow * (0.72 + 0.42 * (1 - grad))[..., None]
                c = np.where(m[..., None], gc, c)

        color = np.where(inside[..., None], c, color)
        normal = np.where(inside[..., None], np.array(nrm, np.float32), normal)
        best_t = np.where(inside, t, best_t)
        is_floor = np.where(inside, name == "floor", is_floor)
        is_wall = np.where(inside, name.startswith("wall"), is_wall)
        hit_y = np.where(inside, hitp[..., 1], hit_y)

    for b in furniture:
        with np.errstate(divide="ignore", invalid="ignore"):
            t0 = (b.lo - O) / d
            t1 = (b.hi - O) / d
        t0 = np.nan_to_num(t0, nan=-1e9, posinf=1e9, neginf=-1e9)
        t1 = np.nan_to_num(t1, nan=1e9, posinf=1e9, neginf=-1e9)
        tmin = np.minimum(t0, t1).max(-1)
        tmax = np.maximum(t0, t1).min(-1)
        hit = (tmax > tmin) & (tmin > 0.02) & (tmin < best_t)
        if not hit.any():
            continue
        hp = O + d * tmin[..., None]
        eps = 1e-2
        nx = np.where(np.abs(hp[..., 0] - b.lo[0]) < eps, -1.0,
                      np.where(np.abs(hp[..., 0] - b.hi[0]) < eps, 1.0, 0.0))
        ny = np.where(np.abs(hp[..., 1] - b.lo[1]) < eps, -1.0,
                      np.where(np.abs(hp[..., 1] - b.hi[1]) < eps, 1.0, 0.0))
        nz = np.where(np.abs(hp[..., 2] - b.lo[2]) < eps, -1.0,
                      np.where(np.abs(hp[..., 2] - b.hi[2]) < eps, 1.0, 0.0))
        nb = np.stack([nx, ny, nz], -1)
        if b.tex is not None:
            top = np.abs(ny) > 0.5
            u = np.where(top, hp[..., 0], hp[..., 0] + hp[..., 2])
            v = np.where(top, hp[..., 2], hp[..., 1])
            c = _sample(b.tex, u, v, b.tile) * b.tint
        else:
            c = np.broadcast_to(b.tint * 255.0, shape + (3,)).copy()
        if b.emissive is not None:
            c = c + np.array(b.emissive, np.float32)
        color = np.where(hit[..., None], c, color)
        normal = np.where(hit[..., None], nb, normal)
        best_t = np.where(hit, tmin, best_t)
        is_floor = np.where(hit, False, is_floor)
        is_wall = np.where(hit, False, is_wall)
        hit_y = np.where(hit, hp[..., 1], hit_y)

    return color, normal, best_t, is_floor, is_wall, hit_y


def _ambient_occlusion(hitp, is_floor, is_wall, room, furniture):
    """Cheap contact darkening: corners and the ground line under furniture."""
    lo, hi = room["lo"], room["hi"]
    ao = np.ones(hitp.shape[:-1], np.float32)

    # wall / floor junction
    dx = np.minimum(hitp[..., 0] - lo[0], hi[0] - hitp[..., 0])
    dz = np.minimum(hitp[..., 2] - lo[2], hi[2] - hitp[..., 2])
    corner = np.minimum(dx, dz)
    ao = np.where(is_floor, np.minimum(ao, 0.62 + 0.38 * np.clip(corner / 1.1, 0, 1)), ao)
    dy = np.minimum(hitp[..., 1] - lo[1], hi[1] - hitp[..., 1])
    ao = np.where(is_wall, np.minimum(ao, 0.70 + 0.30 * np.clip(dy / 0.9, 0, 1)), ao)

    # contact shadow around each furniture footprint
    for b in furniture:
        ex = 0.34
        px = np.clip(hitp[..., 0], b.lo[0], b.hi[0]) - hitp[..., 0]
        pz = np.clip(hitp[..., 2], b.lo[2], b.hi[2]) - hitp[..., 2]
        dist = np.sqrt(px * px + pz * pz)
        s = np.clip(dist / (ex + 0.30), 0, 1)
        under = np.clip(1.0 - s, 0, 1) ** 1.5
        strength = 0.55 * np.clip((b.hi[1] - b.lo[1]) / 0.7, 0.35, 1.0)
        ao = np.where(is_floor & (hitp[..., 1] <= b.lo[1] + 0.02),
                      ao * (1.0 - under * strength), ao)
    return np.clip(ao, 0.18, 1.0)


def _shade(color, normal, t, ao, exposure):
    key = np.array([-0.45, 0.78, -0.42], np.float32)
    key /= np.linalg.norm(key)
    lam = np.clip((normal * key).sum(-1), 0, 1)
    fill = np.clip((normal * np.array([0.6, 0.3, 0.74], np.float32)).sum(-1), 0, 1)
    warm = np.array([1.05, 1.00, 0.93], np.float32)   # sun
    cool = np.array([0.92, 0.97, 1.06], np.float32)   # sky bounce
    lit = (0.46 * ao[..., None]
           + 0.44 * lam[..., None] * warm
           + 0.22 * fill[..., None] * cool)
    out = color * lit * exposure
    fog = np.clip(t / 17.0, 0, 1)[..., None]
    return out * (1 - fog * 0.30) + np.array([26, 42, 52], np.float32) * fog * 0.30


def render(size, cam, room, furniture=(), windows=(), fov=72.0, exposure=1.0,
           reflectivity=0.34):
    """cam: dict(pos=(x,y,z), yaw=deg, pitch=deg). room: dict of faces."""
    W, H = size
    px, py, pz = cam["pos"]
    yaw = np.deg2rad(cam.get("yaw", 0.0))
    pitch = np.deg2rad(cam.get("pitch", 0.0))

    ar = W / H
    f = 1.0 / np.tan(np.deg2rad(fov) / 2)
    sx = (np.arange(W, dtype=np.float32) + 0.5) / W * 2 - 1
    sy = 1 - (np.arange(H, dtype=np.float32) + 0.5) / H * 2
    gx, gy = np.meshgrid(sx * ar, sy)
    dx, dy, dz = gx, gy, np.full_like(gx, f)
    cy, sy_ = np.cos(pitch), np.sin(pitch)
    dy2 = dy * cy - dz * sy_
    dz2 = dy * sy_ + dz * cy
    cx, sxx = np.cos(yaw), np.sin(yaw)
    dx2 = dx * cx + dz2 * sxx
    dz3 = -dx * sxx + dz2 * cx
    n = np.sqrt(dx2 ** 2 + dy2 ** 2 + dz3 ** 2)
    d = np.stack([dx2 / n, dy2 / n, dz3 / n], -1)
    O = np.broadcast_to(np.array([px, py, pz], np.float32), d.shape).copy()

    color, normal, t, is_floor, is_wall, _ = _trace(O, d, room, furniture, windows)
    hitp = O + d * np.minimum(t, 1e4)[..., None]
    ao = _ambient_occlusion(hitp, is_floor, is_wall, room, furniture)
    out = _shade(color, normal, t, ao, exposure)

    # ---- mirror bounce off the polished floor ---------------------------
    if reflectivity > 0 and is_floor.any():
        rd = d.copy()
        rd[..., 1] = -rd[..., 1]
        ro = hitp + FLOOR_N * 2e-3
        rc, rn, rt, rf, rw, _ = _trace(ro, rd, room, furniture, windows)
        rhit = ro + rd * np.minimum(rt, 1e4)[..., None]
        rao = _ambient_occlusion(rhit, rf, rw, room, furniture)
        refl = _shade(rc, rn, rt + t, rao, exposure)
        # blurrier and dimmer with distance travelled, like a honed polish
        soft = np.clip(rt / 5.0, 0, 1)[..., None]
        refl = refl * (1 - soft * 0.45)
        cosi = np.clip(np.abs(d[..., 1]), 0, 1)[..., None]
        fres = reflectivity * (0.30 + 0.70 * (1 - cosi) ** 3)
        m = (is_floor & (rt < 1e8))[..., None]
        out = np.where(m, out * (1 - fres) + refl * fres, out)

    # vignette
    vy, vx = np.mgrid[0:H, 0:W].astype(np.float32)
    r = np.sqrt(((vx / W - .5) * 1.9) ** 2 + ((vy / H - .5) * 1.9) ** 2)
    out *= (1 - np.clip(r - 0.55, 0, 1) * 0.55)[..., None]

    out = np.clip(out, 0, 255)
    out = 255 * (out / 255) ** 0.93
    return np.clip(out, 0, 255).astype(np.uint8)
