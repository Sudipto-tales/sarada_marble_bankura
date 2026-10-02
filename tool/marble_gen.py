"""Procedural marble / granite texture synthesizer (numpy + Pillow).

Deterministic per-marble seeds so a product looks identical everywhere it appears:
catalog card, gallery, texture tile and inside the 3D room.
"""
import numpy as np
from PIL import Image, ImageFilter


# ---------------------------------------------------------------- noise ----
def _periodic_value_noise(h, w, res, rng):
    """Value noise that tiles seamlessly (grid wraps)."""
    g = rng.random((res, res)).astype(np.float32)
    g = np.pad(g, ((0, 1), (0, 1)), mode="wrap")
    ys = np.linspace(0, res, h, endpoint=False, dtype=np.float32)
    xs = np.linspace(0, res, w, endpoint=False, dtype=np.float32)
    y0 = np.floor(ys).astype(np.int32); x0 = np.floor(xs).astype(np.int32)
    fy = (ys - y0)[:, None]; fx = (xs - x0)[None, :]
    # smoothstep
    fy = fy * fy * (3 - 2 * fy); fx = fx * fx * (3 - 2 * fx)
    a = g[y0][:, x0]; b = g[y0][:, x0 + 1]
    c = g[y0 + 1][:, x0]; d = g[y0 + 1][:, x0 + 1]
    return (a * (1 - fx) + b * fx) * (1 - fy) + (c * (1 - fx) + d * fx) * fy


def fbm(h, w, seed, octaves=6, base_res=2, gain=0.5):
    rng = np.random.default_rng(seed)
    out = np.zeros((h, w), np.float32)
    amp, norm, res = 1.0, 0.0, base_res
    for _ in range(octaves):
        out += amp * _periodic_value_noise(h, w, res, rng)
        norm += amp
        amp *= gain
        res *= 2
    return out / norm


def _hex(c):
    c = c.lstrip("#")
    return np.array([int(c[i:i + 2], 16) for i in (0, 2, 4)], np.float32)


# --------------------------------------------------------------- marble ----
def marble_tile(size, spec):
    """Return uint8 RGB array of a seamless marble tile."""
    h = w = size
    seed = spec["seed"]
    yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
    u, v = xx / w, yy / h

    warp = fbm(h, w, seed + 1, octaves=6, base_res=2)
    warp2 = fbm(h, w, seed + 2, octaves=7, base_res=3)
    grain = fbm(h, w, seed + 3, octaves=3, base_res=32)

    ang = np.deg2rad(spec.get("vein_angle", 28.0))
    proj = u * np.cos(ang) + v * np.sin(ang)

    # primary veins ------------------------------------------------------
    # Ridge = distance to the nearest vein centreline, so width is controlled
    # directly instead of falling out of a power curve (which gave wide bands).
    freq = spec.get("vein_freq", 3.0)
    amp = spec.get("warp", 1.6)
    detail = fbm(h, w, seed + 7, octaves=4, base_res=12)
    turb = (warp - 0.5) * amp + (warp2 - 0.5) * amp * 0.5 + (detail - 0.5) * 0.22

    sharp = spec.get("vein_sharp", 9.0)
    vw = spec.get("vein_w", 0.20 / sharp)          # half-width in period units

    phase = proj * freq + turb
    d = np.abs(phase - np.round(phase))            # 0 .. 0.5
    core = np.exp(-((d / vw) ** 2))
    halo = np.exp(-((d / (vw * 6.0)) ** 2)) * spec.get("halo", 0.30)
    # veins fade in and out along their length like real mineral seams
    lenmod = 0.45 + 0.75 * fbm(h, w, seed + 8, octaves=4, base_res=4)
    veins = np.clip((core + halo) * np.clip(lenmod, 0, 1.2), 0, 1)

    # secondary hairline veins -------------------------------------------
    turb2 = (warp2 - 0.5) * amp * 1.4 + (grain - 0.5) * 0.30 + (detail - 0.5) * 0.4
    phase2 = proj * freq * 2.7 + turb2
    d2 = np.abs(phase2 - np.round(phase2))
    vw2 = vw * 0.45
    lenmod2 = np.clip(0.15 + 1.15 * fbm(h, w, seed + 9, octaves=4, base_res=6), 0, 1)
    veins2 = np.clip(np.exp(-((d2 / vw2) ** 2)) * lenmod2, 0, 1)

    # base colour --------------------------------------------------------
    c0, c1 = _hex(spec["base"]), _hex(spec["base2"])
    blend = np.clip(fbm(h, w, seed + 4, octaves=4, base_res=2), 0, 1)[..., None]
    img = c0[None, None, :] * (1 - blend) + c1[None, None, :] * blend

    cv = _hex(spec["vein"])
    m = (veins * spec.get("vein_strength", 0.9))[..., None]
    img = img * (1 - m) + cv[None, None, :] * m

    cv2 = _hex(spec.get("vein2", spec["vein"]))
    m2 = (veins2 * spec.get("vein2_strength", 0.35))[..., None]
    img = img * (1 - m2) + cv2[None, None, :] * m2

    # cloudy mottling -----------------------------------------------------
    cloud = (fbm(h, w, seed + 5, octaves=5, base_res=3) - 0.5)[..., None]
    img += cloud * spec.get("mottle", 14.0)

    # granite speckle -----------------------------------------------------
    sp = spec.get("speckle", 0.0)
    if sp > 0:
        rng = np.random.default_rng(seed + 6)
        n = rng.random((h, w)).astype(np.float32)
        dark = (n < 0.045).astype(np.float32)
        light = (n > 0.968).astype(np.float32)
        sc = _hex(spec.get("speckle_color", "#F2E4C0"))
        img = img * (1 - (light * sp)[..., None]) + sc[None, None, :] * (light * sp)[..., None]
        img -= (dark * sp * 55.0)[..., None]

    # fine grain + polish sheen -------------------------------------------
    img += ((grain - 0.5) * spec.get("grain", 10.0))[..., None]
    if spec.get("finish", "polished") == "polished":
        sheen = (1.0 - np.abs(u - 0.42) * 0.55)[..., None]
        img = img * (0.94 + 0.10 * sheen)

    img = np.clip(img, 0, 255).astype(np.uint8)
    out = Image.fromarray(img)
    if spec.get("finish") == "honed":
        out = out.filter(ImageFilter.GaussianBlur(0.6))
    return np.asarray(out)


# ------------------------------------------------------------- library -----
MARBLES = [
    dict(id="carrara_white", name="Carrara White", seed=1101, base="#F3F1EC", base2="#E6E4DE",
         vein="#9AA1A6", vein2="#C3C7CA", vein_freq=2.6, vein_sharp=7.0, warp=1.7,
         vein_strength=0.62, vein2_strength=0.30, mottle=12, grain=9, vein_angle=32),
    dict(id="statuario", name="Statuario", seed=1202, base="#FAF9F6", base2="#F1EFEA",
         vein="#5E666C", vein2="#A9AFB4", vein_freq=1.7, vein_sharp=5.0, warp=2.1,
         vein_strength=0.86, vein2_strength=0.26, mottle=9, grain=7, vein_angle=52),
    dict(id="calacatta_gold", name="Calacatta Gold", seed=1303, base="#F8F5EE", base2="#EFEADF",
         vein="#8C7A55", vein2="#C9A24B", vein_freq=1.9, vein_sharp=5.5, warp=2.2,
         vein_strength=0.80, vein2_strength=0.42, mottle=11, grain=8, vein_angle=44),
    dict(id="nero_marquina", name="Nero Marquina", seed=1404, base="#14161A", base2="#0B0D10",
         vein="#F2F4F5", vein2="#B9BFC4", vein_freq=2.1, vein_sharp=6.5, warp=2.4,
         vein_strength=0.70, halo=0.16, vein2_strength=0.30, mottle=8, grain=6, vein_angle=61),
    dict(id="imperial_green", name="Imperial Green", seed=1505, base="#16382E", base2="#0E2A22",
         vein="#DCE9DF", vein2="#7FA98F", vein_freq=2.4, vein_sharp=6.0, warp=2.6,
         vein_strength=0.60, halo=0.18, vein2_strength=0.34, mottle=14, grain=9, vein_angle=24),
    dict(id="makrana_white", name="Makrana White", seed=1606, base="#F7F6F1", base2="#EDEBE4",
         vein="#C6C3B9", vein2="#DAD7CF", vein_freq=2.2, vein_sharp=9.0, warp=1.3,
         vein_strength=0.34, vein2_strength=0.18, mottle=8, grain=8, vein_angle=18),
    dict(id="rajasthan_pink", name="Rajasthan Pink", seed=1707, base="#E9D3CB", base2="#DDC0B6",
         vein="#B8877A", vein2="#F2E3DD", vein_freq=2.8, vein_sharp=7.5, warp=1.9,
         vein_strength=0.52, vein2_strength=0.28, mottle=16, grain=11, vein_angle=37),
    dict(id="ambaji_white", name="Ambaji White", seed=1808, base="#F2F2EF", base2="#E4E5E1",
         vein="#9FA6A3", vein2="#CACFCB", vein_freq=2.5, vein_sharp=7.8, warp=1.5,
         vein_strength=0.50, vein2_strength=0.24, mottle=10, grain=9, vein_angle=66),
    dict(id="botticino_beige", name="Botticino Beige", seed=1909, base="#E7DAC4", base2="#DCCBB0",
         vein="#B79E7B", vein2="#F0E7D6", vein_freq=3.1, vein_sharp=8.5, warp=1.6,
         vein_strength=0.44, vein2_strength=0.26, mottle=15, grain=12, vein_angle=29),
    dict(id="crema_marfil", name="Crema Marfil", seed=2010, base="#EFE3CD", base2="#E5D5BA",
         vein="#C0A97F", vein2="#F7EFE0", vein_freq=2.7, vein_sharp=8.0, warp=1.8,
         vein_strength=0.40, vein2_strength=0.30, mottle=13, grain=10, vein_angle=41),
    dict(id="travertine_classic", name="Classic Travertine", seed=2111, base="#E3D6BE", base2="#D6C4A6",
         vein="#B49B77", vein2="#EFE5D2", vein_freq=5.4, vein_sharp=10.0, warp=1.25,
         vein_strength=0.46, vein2_strength=0.20, mottle=18, grain=16, vein_angle=84,
         finish="honed"),
    dict(id="emperador_dark", name="Emperador Dark", seed=2212, base="#3A2A20", base2="#2A1D16",
         vein="#C7A87E", vein2="#8A6B4C", vein_freq=3.4, vein_sharp=7.0, warp=2.3,
         vein_strength=0.50, halo=0.20, vein2_strength=0.34, mottle=14, grain=10, vein_angle=57),
    dict(id="onyx_honey", name="Honey Onyx", seed=2313, base="#E3B978", base2="#D19E56",
         vein="#F7E2BE", vein2="#A9743A", vein_freq=1.6, vein_sharp=4.2, warp=2.8,
         vein_strength=0.55, halo=0.22, vein2_strength=0.40, mottle=20, grain=8, vein_angle=13),
    dict(id="grey_william", name="Grey William", seed=2414, base="#9CA1A6", base2="#878D93",
         vein="#EDEFF1", vein2="#5E656B", vein_freq=2.3, vein_sharp=6.2, warp=2.2,
         vein_strength=0.62, halo=0.20, vein2_strength=0.30, mottle=12, grain=9, vein_angle=49),
    dict(id="katni_beige", name="Katni Beige", seed=2515, base="#DCC9A8", base2="#CDB894",
         vein="#A98C63", vein2="#EFE2CB", vein_freq=3.0, vein_sharp=8.2, warp=1.5,
         vein_strength=0.42, vein2_strength=0.24, mottle=17, grain=13, vein_angle=34),
    dict(id="udaipur_green", name="Udaipur Green", seed=2616, base="#4A6B4E", base2="#38553C",
         vein="#D7E4D2", vein2="#8FAF8B", vein_freq=2.9, vein_sharp=7.0, warp=2.0,
         vein_strength=0.52, halo=0.20, vein2_strength=0.30, mottle=15, grain=11, vein_angle=21),
    dict(id="black_galaxy", name="Black Galaxy Granite", seed=2717, base="#0D0E11", base2="#15171B",
         vein="#1B1D22", vein2="#22252B", vein_freq=5.0, vein_sharp=14.0, warp=0.7,
         vein_strength=0.20, vein2_strength=0.12, mottle=6, grain=7, vein_angle=0,
         speckle=0.85, speckle_color="#E9C98A"),
    dict(id="kashmir_white_granite", name="Kashmir White Granite", seed=2818, base="#E8E3D9", base2="#DCD6C9",
         vein="#B9A98F", vein2="#CFC7B6", vein_freq=4.2, vein_sharp=12.0, warp=1.0,
         vein_strength=0.26, vein2_strength=0.16, mottle=10, grain=12, vein_angle=8,
         speckle=0.55, speckle_color="#8E5C4E"),
    dict(id="sahara_beige", name="Sahara Beige", seed=2919, base="#EADCC2", base2="#DFCDAC",
         vein="#C2A87F", vein2="#F6EEDF", vein_freq=2.4, vein_sharp=8.8, warp=1.4,
         vein_strength=0.38, vein2_strength=0.22, mottle=14, grain=11, vein_angle=27),
    dict(id="bianco_dolomite", name="Bianco Dolomite", seed=3020, base="#F4F4F2", base2="#E9EAE8",
         vein="#A5ADB2", vein2="#D2D7DA", vein_freq=2.0, vein_sharp=6.8, warp=1.9,
         vein_strength=0.58, vein2_strength=0.26, mottle=9, grain=8, vein_angle=71),
]

MARBLE_BY_ID = {m["id"]: m for m in MARBLES}
