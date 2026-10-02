import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../../../data/models/marble_texture.dart';
import '../../../data/models/room.dart';
import '../camera/camera_state.dart';
import '../textures/texture_cache.dart';
import 'room_renderer.dart';

/// Turns a [Room] (plain data) into a [RoomScene] (renderable geometry).
///
/// This is the only place that knows how a room's metres map to world corners,
/// which keeps the data model free of rendering concerns.
class RoomSceneBuilder {
  const RoomSceneBuilder._();

  /// Per-face lighting so a flat cuboid still reads as a lit interior.
  static const Map<String, double> _brightness = {
    'floor': 0.86,
    'ceiling': 1.02,
    'wall_back': 0.94,
    'wall_left': 0.72,
    'wall_right': 1.0,
    'counter': 0.98,
  };

  static Future<RoomScene> build(
    Room room,
    Map<String, MarbleTexture> texturesBySurface, {
    Camera3D? camera,
  }) async {
    final w = room.width / 2;
    final h = room.height;
    final d = room.depth / 2;

    final surfaces = <SceneSurface>[];
    for (final surface in room.surfaces) {
      final corners = _cornersFor(surface.id, w, h, d);
      if (corners == null) continue;
      final texture = texturesBySurface[surface.id];
      ui.Image? image;
      if (texture != null) {
        image = await TextureCache.instance.load(texture.asset);
      }
      surfaces.add(SceneSurface(
        id: surface.id,
        corners: corners,
        tileMetres: surface.tileMetres,
        baseColor: Color(texture?.baseColor ?? 0xFFE9EDEF),
        brightness: _brightness[surface.id] ?? 0.9,
        texture: image,
        glossiness: texture?.glossiness ?? 0.5,
      ));
    }

    return RoomScene(
      surfaces: surfaces,
      hotspots: [
        for (final h in room.hotspots)
          SceneHotspot(
            id: h.id,
            surfaceId: h.surfaceId,
            position: Vec3(h.x, h.y, h.z),
            label: h.label,
          ),
      ],
      camera: camera ??
          Camera3D(
            position: Vec3(0, room.eyeHeight, -room.depth / 2 + 0.55),
            yaw: room.defaultYaw,
            pitch: room.defaultPitch,
            fov: room.fov,
          ),
    );
  }

  /// Corner order: [top-left, top-right, bottom-right, bottom-left] as seen
  /// from inside the room, so the u/v grid runs the way you expect.
  static List<Vec3>? _cornersFor(String id, double w, double h, double d) {
    switch (id) {
      case 'floor':
        return [
          Vec3(-w, 0, d),
          Vec3(w, 0, d),
          Vec3(w, 0, -d),
          Vec3(-w, 0, -d),
        ];
      case 'ceiling':
        return [
          Vec3(-w, h, -d),
          Vec3(w, h, -d),
          Vec3(w, h, d),
          Vec3(-w, h, d),
        ];
      case 'wall_back':
        return [
          Vec3(-w, h, d),
          Vec3(w, h, d),
          Vec3(w, 0, d),
          Vec3(-w, 0, d),
        ];
      case 'wall_left':
        return [
          Vec3(-w, h, -d),
          Vec3(-w, h, d),
          Vec3(-w, 0, d),
          Vec3(-w, 0, -d),
        ];
      case 'wall_right':
        return [
          Vec3(w, h, d),
          Vec3(w, h, -d),
          Vec3(w, 0, -d),
          Vec3(w, 0, d),
        ];
      case 'counter':
        // A waist-high slab running along the back third of the room.
        const y = 0.92;
        final z0 = d * 0.10;
        final z1 = d * 0.52;
        return [
          Vec3(-w * 0.72, y, z1),
          Vec3(w * 0.72, y, z1),
          Vec3(w * 0.72, y, z0),
          Vec3(-w * 0.72, y, z0),
        ];
      default:
        return null;
    }
  }
}
