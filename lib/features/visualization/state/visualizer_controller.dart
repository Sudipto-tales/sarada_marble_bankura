import 'dart:convert';
import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../data/models/marble_texture.dart';
import '../../../data/models/photo_surface.dart';
import '../../../data/models/product.dart';
import '../../../data/models/room.dart';
import '../../../data/models/saved_design.dart';
import '../../../data/models/surface_style.dart';
import '../../../data/repositories/repositories.dart';
import '../camera/camera_state.dart';
import '../rooms/room_renderer.dart';
import '../rooms/room_scene_builder.dart';
import '../textures/texture_cache.dart';

enum VisualizerMode { threeD, twoD }

/// Owns both views' design. Renderers consume the same assignments and patterns.
class VisualizerController extends ChangeNotifier {
  VisualizerController({required this.rooms, required this.catalog});
  final RoomRepository rooms;
  final ProductRepository catalog;
  Room? room;
  List<Product> products = const [];
  Map<String, MarbleTexture> textures = {};
  final Map<String, String> _assignments = {};
  final Map<String, String> _baseline = {};
  final Map<String, SurfaceStyle> _styles = {};
  final Map<String, PhotoSurface> _photoSurfaces = {};
  Map<String, String> get assignments => Map.unmodifiable(_assignments);
  Map<String, PhotoSurface> get photoSurfaces =>
      Map.unmodifiable(_photoSurfaces);
  Map<String, ui.Image> patterns = {};
  final Set<ui.Image> _owned = {};
  RoomScene? scene;
  ui.Image? photo;
  String? photoPng;
  String? designId;
  VisualizerMode mode = VisualizerMode.threeD;
  String selectedSurface = 'floor';
  bool showOriginal = false;
  bool busy = false;
  String? error;
  bool _disposed = false;
  int _revision = 0;
  bool get imported => photoPng != null;
  bool get canShow3D => !imported;
  RoomSurface? get surface => room?.surface(selectedSurface);
  Map<String, String> get visibleAssignments =>
      showOriginal ? _baseline : _assignments;
  SurfaceStyle styleFor(String id) =>
      _styles[id] ??
      SurfaceStyle(
        tileMetres: (room?.surface(id)?.tileMetres ?? 1.2).clamp(.3, 2),
      );
  Product? productFor(String id) =>
      products.where((p) => p.id == visibleAssignments[id]).firstOrNull;
  double? get selectedArea => mode == VisualizerMode.twoD
      ? _photoSurfaces[selectedSurface]?.areaSqFt
      : surface?.areaSqFt;

  Future<void> load({
    String? roomId,
    String? productId,
    String? savedId,
    String? initialMode,
    String? photoPngOverride,
  }) async {
    final presets = await rooms.all();
    if (_disposed) return;
    if (presets.isEmpty) throw StateError('No room presets available.');
    final saved = savedId == null
        ? null
        : (await rooms.savedDesigns())
              .where((d) => d.id == savedId)
              .firstOrNull;
    if (savedId != null && saved == null) {
      throw StateError('Saved design no longer exists.');
    }
    final wanted = saved?.roomId ?? roomId;
    room = presets.where((r) => r.id == wanted).firstOrNull ?? presets.first;
    products = await catalog.all();
    textures = {for (final t in await rooms.textures()) t.id: t};
    if (_disposed) return;
    for (final s in room!.surfaces) {
      final product = products
          .where((p) => p.textureId == s.defaultTextureId)
          .firstOrNull;
      if (product != null) _baseline[s.id] = product.id;
    }
    _assignments.addAll(_baseline);
    if (saved != null) {
      designId = saved.id;
      for (final entry in saved.assignments.entries) {
        if (room!.surface(entry.key) != null &&
            products.any((p) => p.id == entry.value)) {
          _assignments[entry.key] = entry.value;
        }
      }
      _styles.addAll(saved.styles);
      _photoSurfaces.addAll(saved.photoSurfaces);
      if (room!.surface(saved.selectedSurface)?.applyable == true) {
        selectedSurface = saved.selectedSurface;
      }
      photoPng = saved.photoPng;
      mode = saved.mode == 'twoD' || imported
          ? VisualizerMode.twoD
          : VisualizerMode.threeD;
    }
    if (photoPngOverride != null) {
      photoPng = photoPngOverride;
      mode = VisualizerMode.twoD;
    }
    if (initialMode != null) {
      mode = initialMode == 'twoD' || imported
          ? VisualizerMode.twoD
          : VisualizerMode.threeD;
    }
    if (productId != null && products.any((p) => p.id == productId)) {
      _assignments['floor'] = productId;
    }
    scene = await RoomSceneBuilder.build(room!, {});
    final camera = saved?.camera;
    if (camera != null && camera.isNotEmpty) {
      scene = scene!.copyWith(
        camera: scene!.camera.copyWith(
          yaw: camera['yaw'],
          pitch: camera['pitch'],
          fov: camera['fov'],
        ),
      );
    }
    final bytes = photoPng == null
        ? (await rootBundle.load(room!.preview)).buffer.asUint8List()
        : base64Decode(photoPng!);
    final decoded = await _decode(bytes);
    if (_disposed) {
      decoded.dispose();
      return;
    }
    photo = decoded;
    _owned.add(decoded);
    await _render();
  }

  void setMode(VisualizerMode value) {
    if (value == VisualizerMode.threeD && !canShow3D) return;
    mode = value;
    notifyListeners();
  }

  Future<void> switchRoom(Room newRoom) async {
    if (room?.id == newRoom.id) return;
    room = newRoom;
    final newBaseline = <String, String>{};
    for (final s in newRoom.surfaces) {
      final product = products
          .where((p) => p.textureId == s.defaultTextureId)
          .firstOrNull;
      if (product != null) newBaseline[s.id] = product.id;
    }
    _baseline
      ..clear()
      ..addAll(newBaseline);
    final preserved = <String, String>{};
    for (final entry in _assignments.entries) {
      if (newRoom.surface(entry.key) != null) {
        preserved[entry.key] = entry.value;
      }
    }
    _assignments
      ..clear()
      ..addAll(newBaseline)
      ..addAll(preserved);
    if (newRoom.surface(selectedSurface)?.applyable != true) {
      selectedSurface = newRoom.surfaces.firstWhere((s) => s.applyable).id;
    }
    scene = await RoomSceneBuilder.build(newRoom, {});
    if (!imported) {
      final bytes =
          (await rootBundle.load(newRoom.preview)).buffer.asUint8List();
      final decoded = await _decode(bytes);
      if (!_disposed) {
        final old = photo;
        photo = decoded;
        _owned.add(decoded);
        if (old != null) _releaseAfterFrame([old]);
      }
    }
    await _render();
  }

  void selectSurface(String id) {
    if (room?.surface(id)?.applyable != true) return;
    selectedSurface = id;
    notifyListeners();
  }

  void setCamera(Camera3D camera) {
    scene = scene?.copyWith(camera: camera);
    notifyListeners();
  }

  Future<void> apply(String surfaceId, Product product) async {
    if (!textures.containsKey(product.textureId)) return;
    _assignments[surfaceId] = product.id;
    showOriginal = false;
    await _render();
  }

  Future<void> compare(bool original) async {
    showOriginal = original;
    await _render();
  }

  Future<void> setStyle(String id, SurfaceStyle style) async {
    _styles[id] = style;
    showOriginal = false;
    await _render();
  }

  void setPhotoSurface(String id, PhotoSurface value) {
    _photoSurfaces[id] = value;
    notifyListeners();
  }

  Future<void> importPhoto(Uint8List normalizedPng) async {
    final decoded = await _decode(normalizedPng);
    if (_disposed) {
      decoded.dispose();
      return;
    }
    final old = photo;
    photo = decoded;
    _owned.add(decoded);
    photoPng = base64Encode(normalizedPng);
    _photoSurfaces.clear();
    mode = VisualizerMode.twoD;
    showOriginal = false;
    notifyListeners();
    if (old != null) _releaseAfterFrame([old]);
    await _render();
  }

  Future<void> usePresetPhoto() async {
    final bytes = (await rootBundle.load(room!.preview)).buffer.asUint8List();
    final decoded = await _decode(bytes);
    if (_disposed) {
      decoded.dispose();
      return;
    }
    final old = photo;
    photo = decoded;
    _owned.add(decoded);
    photoPng = null;
    _photoSurfaces.clear();
    notifyListeners();
    if (old != null) _releaseAfterFrame([old]);
  }

  SavedDesign saveAs(String name) => SavedDesign(
    id: designId ?? 'd_${DateTime.now().microsecondsSinceEpoch}',
    name: name,
    roomId: room!.id,
    createdOn: DateTime.now(),
    assignments: Map.of(_assignments),
    styles: Map.of(_styles),
    photoSurfaces: Map.of(_photoSurfaces),
    photoPng: photoPng,
    mode: mode.name,
    selectedSurface: selectedSurface,
    camera: {
      'yaw': scene!.camera.yaw,
      'pitch': scene!.camera.pitch,
      'fov': scene!.camera.fov,
    },
  );

  Future<void> _render() async {
    final revision = ++_revision;
    busy = true;
    error = null;
    notifyListeners();
    final next = <String, ui.Image>{};
    final appliedStyles = <String, SurfaceStyle>{};
    try {
      for (final entry in Map.of(visibleAssignments).entries) {
        final product = products.where((p) => p.id == entry.value).firstOrNull;
        final texture = textures[product?.textureId];
        if (texture == null) continue;
        final source = await TextureCache.instance.load(texture.asset);
        if (_disposed || revision != _revision) break;
        final style = showOriginal
            ? SurfaceStyle(
                tileMetres: room!.surface(entry.key)!.tileMetres,
                groutMm: 0,
              )
            : styleFor(entry.key);
        appliedStyles[entry.key] = style;
        next[entry.key] = await _pattern(source, style);
      }
      if (_disposed || revision != _revision) {
        for (final image in next.values) {
          image.dispose();
        }
        return;
      }
      final old = patterns.values.toList();
      patterns = next;
      _owned.addAll(next.values);
      scene = scene!.copyWith(
        surfaces: [
          for (final s in scene!.surfaces)
            s.withTexture(
              next[s.id],
              repeatMetres: appliedStyles[s.id]?.tileMetres,
            ),
        ],
      );
      busy = false;
      notifyListeners();
      _releaseAfterFrame(old);
    } catch (_) {
      for (final image in next.values) {
        image.dispose();
      }
      if (!_disposed && revision == _revision) {
        busy = false;
        error = 'Could not load the stone. Try selecting it again.';
        notifyListeners();
      }
    }
  }

  void _releaseAfterFrame(Iterable<ui.Image> images) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      for (final image in images) {
        if (_owned.remove(image)) image.dispose();
      }
    });
  }

  static Future<ui.Image> _decode(Uint8List bytes) async {
    final codec = await ui.instantiateImageCodec(bytes);
    try {
      return (await codec.getNextFrame()).image;
    } finally {
      codec.dispose();
    }
  }

  static Future<ui.Image> _pattern(ui.Image source, SurfaceStyle style) async {
    const side = 512.0;
    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder);
    canvas.save();
    canvas.translate(side / 2, side / 2);
    canvas.rotate(style.rotation * math.pi / 180);
    canvas.drawImageRect(
      source,
      Rect.fromLTWH(0, 0, source.width.toDouble(), source.height.toDouble()),
      const Rect.fromLTWH(-side / 2, -side / 2, side, side),
      Paint()..filterQuality = FilterQuality.medium,
    );
    canvas.restore();
    if (style.groutMm > 0) {
      final border = side * style.groutMm / (style.tileMetres * 1000);
      canvas.drawRect(
        const Rect.fromLTWH(0, 0, side, side),
        Paint()
          ..color = Color(style.groutColor)
          ..style = PaintingStyle.stroke
          ..strokeWidth = border,
      );
    }
    final picture = recorder.endRecording();
    try {
      return await picture.toImage(512, 512);
    } finally {
      picture.dispose();
    }
  }

  @override
  void dispose() {
    _disposed = true;
    _revision++;
    for (final image in _owned) {
      image.dispose();
    }
    _owned.clear();
    super.dispose();
  }
}
