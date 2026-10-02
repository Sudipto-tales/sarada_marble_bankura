import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';

import '../camera/camera_state.dart';
import 'room_renderer.dart';

/// Pure-Dart perspective renderer for a cuboid room.
///
/// Each surface is subdivided into a grid of quads which are drawn with
/// `drawVertices` and a tiled [ImageShader]; the subdivision is what makes the
/// affine per-triangle mapping read as perspective-correct. Per-vertex colours
/// carry the lighting, modulated onto the texture.
class CuboidRoomRenderer implements RoomRenderer {
  CuboidRoomRenderer({this.subdivisions = 10});

  /// Cells per surface edge. 10 is the sweet spot on mid-range phones.
  final int subdivisions;

  final GlobalKey _paintKey = GlobalKey();
  final ValueNotifier<int> _revision = ValueNotifier(0);

  RoomScene? _scene;
  RoomScene? get scene => _scene;

  @override
  Future<void> load(RoomScene scene) async {
    _scene = scene;
    _bump();
  }

  @override
  void setCamera(Camera3D camera) {
    final s = _scene;
    if (s == null) return;
    _scene = s.copyWith(camera: camera);
    _bump();
  }

  @override
  void applyTexture(String surfaceId, ui.Image? texture,
      {Color? tint, double? gloss}) {
    final s = _scene;
    if (s == null) return;
    _scene = s.copyWith(
      surfaces: [
        for (final surface in s.surfaces)
          surface.id == surfaceId
              ? surface.withTexture(texture, color: tint, gloss: gloss)
              : surface,
      ],
    );
    _bump();
  }

  @override
  Projected project(Vec3 world, Size size) =>
      (_scene?.camera ?? const Camera3D(position: Vec3(0, 1.5, 0)))
          .project(world, size);

  @override
  Widget build(BuildContext context) {
    return RepaintBoundary(
      key: _paintKey,
      child: ValueListenableBuilder<int>(
        valueListenable: _revision,
        builder: (context, _, _) => CustomPaint(
          painter: _RoomPainter(_scene, subdivisions),
          size: Size.infinite,
        ),
      ),
    );
  }

  @override
  Future<ui.Image?> snapshot() async {
    final boundary =
        _paintKey.currentContext?.findRenderObject() as RenderRepaintBoundary?;
    if (boundary == null) return null;
    return boundary.toImage(pixelRatio: 2);
  }

  @override
  void dispose() => _revision.dispose();

  void _bump() => _revision.value++;
}

class _RoomPainter extends CustomPainter {
  _RoomPainter(this.scene, this.subdivisions);

  final RoomScene? scene;
  final int subdivisions;

  @override
  void paint(Canvas canvas, Size size) {
    final s = scene;
    canvas.drawRect(
      Offset.zero & size,
      Paint()..color = const Color(0xFF0B1B26),
    );
    if (s == null) return;

    // Painter's algorithm: far surfaces first.
    final ordered = [...s.surfaces];
    ordered.sort((a, b) => _depth(b, s, size).compareTo(_depth(a, s, size)));

    for (final surface in ordered) {
      _paintSurface(canvas, size, s, surface);
    }
  }

  double _depth(SceneSurface surface, RoomScene s, Size size) {
    var sum = 0.0;
    for (final c in surface.corners) {
      sum += s.camera.project(c, size).depth;
    }
    return sum / surface.corners.length;
  }

  void _paintSurface(
      Canvas canvas, Size size, RoomScene s, SceneSurface surface) {
    final n = subdivisions;
    final image = surface.texture;
    final c0 = surface.corners[0];
    final c1 = surface.corners[1];
    final c2 = surface.corners[2];
    final c3 = surface.corners[3];

    // Metres spanned by the surface, so tiling stays physically consistent.
    final uSpan = (c1 - c0).length;
    final vSpan = (c3 - c0).length;
    final repeatU = math.max(uSpan / surface.tileMetres, 0.25);
    final repeatV = math.max(vSpan / surface.tileMetres, 0.25);
    final texW = (image?.width ?? 1).toDouble();
    final texH = (image?.height ?? 1).toDouble();

    final positions = <Offset>[];
    final texCoords = <Offset>[];
    final colors = <Color>[];
    final indices = <int>[];

    final grid = List<List<Projected>>.generate(
      n + 1,
      (i) => List<Projected>.generate(n + 1, (j) {
        final u = i / n;
        final v = j / n;
        final top = Vec3.lerp(c0, c1, u);
        final bottom = Vec3.lerp(c3, c2, u);
        return s.camera.project(Vec3.lerp(top, bottom, v), size);
      }),
    );

    for (var i = 0; i <= n; i++) {
      for (var j = 0; j <= n; j++) {
        final u = i / n;
        final v = j / n;
        final p = grid[i][j];
        positions.add(p.offset);
        texCoords.add(Offset(u * repeatU * texW, v * repeatV * texH));
        colors.add(_vertexColor(surface, u, v));
      }
    }

    var quads = 0;
    for (var i = 0; i < n; i++) {
      for (var j = 0; j < n; j++) {
        final a = i * (n + 1) + j;
        final b = (i + 1) * (n + 1) + j;
        final c = (i + 1) * (n + 1) + j + 1;
        final d = i * (n + 1) + j + 1;
        // Skip cells that straddle the near plane; they project to garbage.
        if (!grid[i][j].visible ||
            !grid[i + 1][j].visible ||
            !grid[i + 1][j + 1].visible ||
            !grid[i][j + 1].visible) {
          continue;
        }
        indices.addAll([a, b, c, a, c, d]);
        quads++;
      }
    }
    if (quads == 0) return;

    final paint = Paint()..isAntiAlias = true;
    if (image != null) {
      paint.shader = ImageShader(
        image,
        TileMode.repeated,
        TileMode.repeated,
        Matrix4.identity().storage,
        filterQuality: FilterQuality.medium,
      );
    } else {
      paint.color = surface.baseColor;
    }

    final vertices = ui.Vertices(
      ui.VertexMode.triangles,
      positions,
      textureCoordinates: image != null ? texCoords : null,
      colors: colors,
      indices: indices,
    );
    canvas.drawVertices(vertices, BlendMode.modulate, paint);
    vertices.dispose();
  }

  /// Cheap baked lighting: brighter towards the light-side corner, darker into
  /// the far corners, plus a soft gloss band on polished surfaces.
  Color _vertexColor(SceneSurface surface, double u, double v) {
    final base = surface.brightness;
    final corner = 1 - (0.22 * ((u - 0.32).abs() + (v - 0.28).abs()));
    final gloss = surface.glossiness > 0.55
        ? 0.10 * math.exp(-math.pow((v - 0.24) * 3.1, 2).toDouble())
        : 0.0;
    final k = (base * corner + gloss).clamp(0.18, 1.35);
    final channel = (255 * k).clamp(0, 255).round();
    return Color.fromARGB(255, channel, channel, channel);
  }

  @override
  bool shouldRepaint(covariant _RoomPainter old) =>
      old.scene != scene || old.subdivisions != subdivisions;
}
