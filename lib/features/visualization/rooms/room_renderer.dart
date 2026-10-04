import 'dart:ui' as ui;

import 'package:flutter/widgets.dart';

import '../camera/camera_state.dart';

/// One paintable surface of a room.
class SceneSurface {
  const SceneSurface({
    required this.id,
    required this.corners,
    required this.tileMetres,
    required this.baseColor,
    required this.brightness,
    this.texture,
    this.glossiness = 0.6,
  });

  /// Four corners in world space, counter-clockwise seen from inside.
  final String id;
  final List<Vec3> corners;
  final double tileMetres;
  final Color baseColor;

  /// Flat lighting factor applied to the whole face.
  final double brightness;
  final ui.Image? texture;
  final double glossiness;

  SceneSurface withTexture(
    ui.Image? image, {
    Color? color,
    double? gloss,
    double? repeatMetres,
  }) => SceneSurface(
    id: id,
    corners: corners,
    tileMetres: repeatMetres ?? tileMetres,
    baseColor: color ?? baseColor,
    brightness: brightness,
    texture: image,
    glossiness: gloss ?? glossiness,
  );
}

/// A hotspot anchored in world space.
class SceneHotspot {
  const SceneHotspot({
    required this.id,
    required this.surfaceId,
    required this.position,
    required this.label,
  });

  final String id;
  final String surfaceId;
  final Vec3 position;
  final String label;
}

/// Everything needed to draw one frame.
class RoomScene {
  const RoomScene({
    required this.surfaces,
    required this.hotspots,
    required this.camera,
  });

  final List<SceneSurface> surfaces;
  final List<SceneHotspot> hotspots;
  final Camera3D camera;

  RoomScene copyWith({List<SceneSurface>? surfaces, Camera3D? camera}) =>
      RoomScene(
        surfaces: surfaces ?? this.surfaces,
        hotspots: hotspots,
        camera: camera ?? this.camera,
      );
}

/// The seam between the app and whatever draws the room.
///
/// Today this is a pure-Dart perspective painter. Swapping in a WebGL / GPU
/// implementation later means writing another class that implements this
/// interface — no screen, model or repository changes.
abstract class RoomRenderer {
  /// Prepare textures and geometry.
  Future<void> load(RoomScene scene);

  /// Move the camera.
  void setCamera(Camera3D camera);

  /// Apply a texture to one surface.
  void applyTexture(
    String surfaceId,
    ui.Image? texture, {
    Color? tint,
    double? gloss,
  });

  /// The widget that paints the current scene.
  Widget build(BuildContext context);

  /// Screen position of a world point, for hotspot overlays.
  Projected project(Vec3 world, Size size);

  /// Grab the current frame (used by "save design" / "share").
  Future<ui.Image?> snapshot();

  void dispose();
}
