import 'dart:math' as math;
import 'dart:ui';

/// Minimal 3-component vector. Avoids pulling in a math package for the
/// handful of operations the renderer needs.
class Vec3 {
  const Vec3(this.x, this.y, this.z);

  final double x;
  final double y;
  final double z;

  Vec3 operator +(Vec3 o) => Vec3(x + o.x, y + o.y, z + o.z);
  Vec3 operator -(Vec3 o) => Vec3(x - o.x, y - o.y, z - o.z);
  Vec3 operator *(double s) => Vec3(x * s, y * s, z * s);

  double dot(Vec3 o) => x * o.x + y * o.y + z * o.z;
  double get length => math.sqrt(x * x + y * y + z * z);

  Vec3 get normalized {
    final l = length;
    return l == 0 ? this : Vec3(x / l, y / l, z / l);
  }

  static Vec3 lerp(Vec3 a, Vec3 b, double t) =>
      Vec3(a.x + (b.x - a.x) * t, a.y + (b.y - a.y) * t, a.z + (b.z - a.z) * t);

  @override
  String toString() => 'Vec3(${x.toStringAsFixed(2)}, '
      '${y.toStringAsFixed(2)}, ${z.toStringAsFixed(2)})';
}

/// A point projected into screen space, with the camera-space depth kept so
/// the painter can cull and sort.
class Projected {
  const Projected(this.offset, this.depth);

  final Offset offset;
  final double depth;

  bool get visible => depth > 0.05;
}

/// Look-around camera: fixed position, free yaw/pitch, adjustable FOV.
/// Matches the offline Python renderer so baked stills and the live room agree.
class Camera3D {
  const Camera3D({
    required this.position,
    this.yaw = 0,
    this.pitch = -10,
    this.fov = 74,
  });

  final Vec3 position;

  /// Degrees. Positive yaw turns right, positive pitch looks up.
  final double yaw;
  final double pitch;
  final double fov;

  static const double minPitch = -34;
  static const double maxPitch = 26;
  static const double minFov = 42;
  static const double maxFov = 96;

  Camera3D copyWith({Vec3? position, double? yaw, double? pitch, double? fov}) =>
      Camera3D(
        position: position ?? this.position,
        yaw: yaw ?? this.yaw,
        pitch: (pitch ?? this.pitch).clamp(minPitch, maxPitch),
        fov: (fov ?? this.fov).clamp(minFov, maxFov),
      );

  Camera3D rotatedBy(double dYaw, double dPitch) =>
      copyWith(yaw: yaw + dYaw, pitch: pitch + dPitch);

  Camera3D zoomedBy(double factor) => copyWith(fov: fov / factor);

  /// World -> camera space (yaw then pitch), then perspective divide.
  Projected project(Vec3 world, Size size) {
    final rel = world - position;
    final cy = math.cos(_rad(yaw));
    final sy = math.sin(_rad(yaw));
    // Inverse yaw rotation about Y.
    final x1 = rel.x * cy - rel.z * sy;
    final z1 = rel.x * sy + rel.z * cy;
    final cp = math.cos(_rad(pitch));
    final sp = math.sin(_rad(pitch));
    // Inverse pitch rotation about X.
    final y2 = rel.y * cp - z1 * sp;
    final z2 = rel.y * sp + z1 * cp;

    final f = 1 / math.tan(_rad(fov) / 2);
    final aspect = size.width / size.height;
    if (z2 <= 0.0001) {
      return Projected(Offset(size.width / 2, size.height / 2), z2);
    }
    final ndcX = (x1 * f / aspect) / z2;
    final ndcY = (y2 * f) / z2;
    return Projected(
      Offset(
        (ndcX + 1) / 2 * size.width,
        (1 - ndcY) / 2 * size.height,
      ),
      z2,
    );
  }

  static double _rad(double deg) => deg * math.pi / 180;
}
