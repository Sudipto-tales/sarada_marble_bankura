/// A visualizable room. Geometry is described in metres so the runtime
/// renderer and the offline pre-renders agree.
class Room {
  const Room({
    required this.id,
    required this.name,
    required this.type,
    required this.preview,
    required this.thumb,
    required this.width,
    required this.height,
    required this.depth,
    required this.surfaces,
    required this.hotspots,
    this.description = '',
    this.defaultYaw = 0,
    this.defaultPitch = -10,
    this.fov = 74,
    this.eyeHeight = 1.55,
  });

  final String id;
  final String name;
  final String type;
  final String preview;
  final String thumb;
  final double width;
  final double height;
  final double depth;
  final List<RoomSurface> surfaces;
  final List<Hotspot> hotspots;
  final String description;
  final double defaultYaw;
  final double defaultPitch;
  final double fov;
  final double eyeHeight;

  RoomSurface? surface(String id) {
    for (final s in surfaces) {
      if (s.id == id) return s;
    }
    return null;
  }
}

enum SurfaceKind { floor, wall, ceiling, counter, feature }

class RoomSurface {
  const RoomSurface({
    required this.id,
    required this.label,
    required this.kind,
    required this.areaSqFt,
    required this.defaultTextureId,
    this.tileMetres = 1.3,
    this.applyable = true,
  });

  final String id;
  final String label;
  final SurfaceKind kind;

  /// Used by the bridge to report an estimated area back to the shop.
  final double areaSqFt;
  final String defaultTextureId;
  final double tileMetres;

  /// Some surfaces (e.g. glass) cannot take marble.
  final bool applyable;
}

/// Tap target floating in 3D space that selects a surface.
class Hotspot {
  const Hotspot({
    required this.id,
    required this.surfaceId,
    required this.x,
    required this.y,
    required this.z,
    required this.label,
  });

  final String id;
  final String surfaceId;

  /// Room-space position in metres, origin at room centre, y up.
  final double x;
  final double y;
  final double z;
  final String label;
}
