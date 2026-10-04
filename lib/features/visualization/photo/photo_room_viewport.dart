import 'dart:math' as math;
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import '../../../data/models/photo_surface.dart';
import '../../../data/models/surface_style.dart';
import 'perspective_mapper.dart';

class PhotoRoomPainter extends CustomPainter {
  PhotoRoomPainter({
    required this.photo,
    required this.surfaces,
    required this.patterns,
    required this.styles,
    this.original = false,
  });
  final ui.Image photo;
  final Map<String, PhotoSurface> surfaces;
  final Map<String, ui.Image> patterns;
  final Map<String, SurfaceStyle> styles;
  final bool original;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawImageRect(
      photo,
      Rect.fromLTWH(0, 0, photo.width.toDouble(), photo.height.toDouble()),
      Offset.zero & size,
      Paint()..filterQuality = FilterQuality.medium,
    );
    if (original) return;
    for (final entry in surfaces.entries) {
      final surface = entry.value;
      final texture = patterns[entry.key];
      if (texture == null || !PerspectiveMapper.isValid(surface.corners)) {
        continue;
      }
      final mapper = PerspectiveMapper(surface.corners);
      final style = styles[entry.key] ?? const SurfaceStyle();
      final repeatX = (surface.widthMetres ?? 4) / style.tileMetres;
      final repeatY = (surface.heightMetres ?? 4) / style.tileMetres;
      final path = Path()
        ..addPolygon([
          for (final p in surface.corners)
            Offset(p.dx * size.width, p.dy * size.height),
        ], true);
      // Subtract protected foreground areas, revealing the original photo.
      final mask = Path();
      final radius = math.min(size.width, size.height) * .025;
      for (final p in surface.exclusions) {
        mask.addOval(
          Rect.fromCircle(
            center: Offset(p.dx * size.width, p.dy * size.height),
            radius: radius,
          ),
        );
      }
      final clip = surface.exclusions.isEmpty
          ? path
          : Path.combine(PathOperation.difference, path, mask);
      canvas.save();
      canvas.clipPath(clip);
      const n = 32;
      final positions = <Offset>[], uv = <Offset>[];
      final indices = <int>[];
      for (var y = 0; y <= n; y++) {
        for (var x = 0; x <= n; x++) {
          final p = mapper.project(x / n, y / n);
          positions.add(Offset(p.dx * size.width, p.dy * size.height));
          uv.add(
            Offset(
              x / n * repeatX * texture.width,
              y / n * repeatY * texture.height,
            ),
          );
          if (x < n && y < n) {
            final a = y * (n + 1) + x, b = a + 1, c = a + n + 2, d = a + n + 1;
            indices.addAll([a, b, c, a, c, d]);
          }
        }
      }
      final shader = ui.ImageShader(
        texture,
        TileMode.repeated,
        TileMode.repeated,
        Matrix4.identity().storage,
        filterQuality: FilterQuality.medium,
      );
      final vertices = ui.Vertices(
        ui.VertexMode.triangles,
        positions,
        textureCoordinates: uv,
        indices: indices,
      );
      canvas.drawVertices(
        vertices,
        BlendMode.srcOver,
        Paint()..shader = shader,
      );
      vertices.dispose();
      shader.dispose();
      canvas.restore();
    }
  }

  @override
  bool shouldRepaint(covariant PhotoRoomPainter oldDelegate) => true;
}

class PhotoRoomViewport extends StatelessWidget {
  const PhotoRoomViewport({
    super.key,
    required this.photo,
    required this.surfaces,
    required this.patterns,
    required this.styles,
    required this.original,
    this.captureKey,
    this.transformationController,
  });
  final ui.Image photo;
  final Map<String, PhotoSurface> surfaces;
  final Map<String, ui.Image> patterns;
  final Map<String, SurfaceStyle> styles;
  final bool original;
  final GlobalKey? captureKey;
  final TransformationController? transformationController;
  @override
  Widget build(BuildContext context) => InteractiveViewer(
    transformationController: transformationController,
    minScale: 1,
    maxScale: 4,
    child: Center(
      child: AspectRatio(
        aspectRatio: photo.width / photo.height,
        child: RepaintBoundary(
          key: captureKey,
          child: CustomPaint(
            painter: PhotoRoomPainter(
              photo: photo,
              surfaces: surfaces,
              patterns: patterns,
              styles: styles,
              original: original,
            ),
          ),
        ),
      ),
    ),
  );
}
