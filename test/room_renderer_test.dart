import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/features/visualization/camera/camera_state.dart';
import 'package:maa_sarada/features/visualization/rooms/cuboid_room_renderer.dart';
import 'package:maa_sarada/features/visualization/rooms/room_renderer.dart';
import 'package:maa_sarada/features/visualization/rooms/room_scene_builder.dart';
import 'package:maa_sarada/features/visualization/textures/texture_cache.dart';
import 'package:maa_sarada/data/models/marble_texture.dart';
import 'package:maa_sarada/data/models/room.dart';
import 'package:maa_sarada/data/static/static_rooms.dart';
import 'package:maa_sarada/data/static/static_textures.dart';

const _size = Size(390, 640);

Map<String, MarbleTexture> _defaultTextures(Room room) {
  final byId = {for (final t in kTextures) t.id: t};
  return {
    for (final s in room.surfaces)
      if (byId[s.defaultTextureId] != null) s.id: byId[s.defaultTextureId]!,
  };
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('camera', () {
    const camera = Camera3D(position: Vec3(0, 1.5, -2), yaw: 0, pitch: 0);

    test('a point dead ahead lands in the centre of the screen', () {
      final p = camera.project(const Vec3(0, 1.5, 2), _size);
      expect(p.visible, isTrue);
      expect(p.offset.dx, closeTo(_size.width / 2, 0.01));
      expect(p.offset.dy, closeTo(_size.height / 2, 0.01));
    });

    test('right is right, up is up, and behind the camera is not visible', () {
      final right = camera.project(const Vec3(2, 1.5, 2), _size);
      final up = camera.project(const Vec3(0, 3.0, 2), _size);
      final behind = camera.project(const Vec3(0, 1.5, -6), _size);

      expect(right.offset.dx, greaterThan(_size.width / 2));
      expect(up.offset.dy, lessThan(_size.height / 2));
      expect(behind.visible, isFalse);
    });

    test('turning the camera moves the world the other way', () {
      final straight = camera.project(const Vec3(0, 1.5, 2), _size);
      final turned = camera.rotatedBy(20, 0).project(const Vec3(0, 1.5, 2), _size);
      expect(turned.offset.dx, lessThan(straight.offset.dx));
    });

    test('pitch and fov stay inside their clamps', () {
      expect(camera.rotatedBy(0, -500).pitch, Camera3D.minPitch);
      expect(camera.rotatedBy(0, 500).pitch, Camera3D.maxPitch);
      expect(camera.zoomedBy(0.01).fov, Camera3D.maxFov);
      expect(camera.zoomedBy(100).fov, Camera3D.minFov);
    });

    test('zooming in makes the same wall span more pixels', () {
      final wide = camera.copyWith(fov: 90).project(const Vec3(1, 1.5, 2), _size);
      final tele = camera.copyWith(fov: 50).project(const Vec3(1, 1.5, 2), _size);
      final wideOffset = (wide.offset.dx - _size.width / 2).abs();
      final teleOffset = (tele.offset.dx - _size.width / 2).abs();
      expect(teleOffset, greaterThan(wideOffset));
    });
  });

  testWidgets('every demo room builds, paints and re-textures without error',
      (tester) async {
    for (final room in kRooms) {
      // Decoding real image assets needs the real event loop, not fake async.
      final scene = (await tester.runAsync(
        () => RoomSceneBuilder.build(room, _defaultTextures(room)),
      ))!;

      expect(scene.surfaces, isNotEmpty, reason: room.id);
      expect(scene.hotspots.length, room.hotspots.length, reason: room.id);
      for (final s in scene.surfaces) {
        expect(s.corners.length, 4, reason: '${room.id}/${s.id}');
        expect(s.texture, isNotNull, reason: '${room.id}/${s.id} texture loaded');
      }

      final renderer = CuboidRoomRenderer();
      await renderer.load(scene);
      renderer.setCamera(scene.camera.rotatedBy(12, -4));

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: SizedBox.fromSize(
            size: _size,
            child: Builder(builder: renderer.build),
          ),
        ),
      ));
      await tester.pump();
      expect(tester.takeException(), isNull, reason: room.id);

      // Swapping a surface texture is the whole point of the module.
      final applyable = room.surfaces.firstWhere((s) => s.applyable);
      final other = kTextures.firstWhere((t) => t.id != applyable.defaultTextureId);
      final image = await tester.runAsync(
        () => TextureCache.instance.load(other.asset),
      );
      renderer.applyTexture(applyable.id, image, tint: const Color(0xFFFFFFFF));
      await tester.pump();
      expect(tester.takeException(), isNull, reason: '${room.id} retexture');

      renderer.dispose();
    }
  });

  testWidgets('hotspots project onto the screen from the default camera',
      (tester) async {
    final room = kRooms.first;
    final scene = (await tester.runAsync(
      () => RoomSceneBuilder.build(room, _defaultTextures(room)),
    ))!;
    final renderer = CuboidRoomRenderer();
    await renderer.load(scene);

    var visible = 0;
    for (final h in scene.hotspots) {
      final p = renderer.project(h.position, _size);
      if (p.visible) visible++;
    }
    expect(visible, greaterThan(0),
        reason: 'at least one hotspot must be reachable without turning');
    renderer.dispose();
  });

  testWidgets('a surface with no texture still renders (asset failure path)',
      (tester) async {
    final room = kRooms.first;
    final scene = (await tester.runAsync(
      () => RoomSceneBuilder.build(room, const {}),
    ))!;
    final renderer = CuboidRoomRenderer();
    await renderer.load(scene);

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SizedBox.fromSize(
          size: _size,
          child: Builder(builder: renderer.build),
        ),
      ),
    ));
    await tester.pump();
    expect(tester.takeException(), isNull);
    renderer.dispose();
  });

  test('the texture cache decodes assets once and reuses the image', () async {
    final asset = kTextures.first.asset;
    final a = await TextureCache.instance.load(asset);
    final b = await TextureCache.instance.load(asset);
    expect(identical(a, b), isTrue);
    expect(a, isA<ui.Image>());
    expect(a.width, greaterThan(0));
  });

  test('CuboidRoomRenderer satisfies the swappable RoomRenderer contract', () {
    expect(CuboidRoomRenderer(), isA<RoomRenderer>());
  });
}
