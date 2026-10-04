import 'dart:ui';

/// Projective mapping from a unit square to a convex, ordered quadrilateral.
class PerspectiveMapper {
  PerspectiveMapper._(
    this.a,
    this.b,
    this.c,
    this.d,
    this.e,
    this.f,
    this.g,
    this.h,
  );
  final double a, b, c, d, e, f, g, h;

  static bool isValid(List<Offset> points) {
    if (points.length != 4 ||
        points.any((p) => !p.dx.isFinite || !p.dy.isFinite)) {
      return false;
    }
    double? sign;
    var area = 0.0;
    for (var i = 0; i < 4; i++) {
      final p = points[i], q = points[(i + 1) % 4], r = points[(i + 2) % 4];
      final cross =
          (q.dx - p.dx) * (r.dy - q.dy) - (q.dy - p.dy) * (r.dx - q.dx);
      if (cross.abs() < .00001 || (sign != null && cross * sign <= 0)) {
        return false;
      }
      sign = cross;
      area += p.dx * q.dy - p.dy * q.dx;
    }
    return area.abs() > .002;
  }

  factory PerspectiveMapper(List<Offset> p) {
    if (!isValid(p)) {
      throw ArgumentError('Surface corners must form a convex quadrilateral.');
    }
    final dx1 = p[1].dx - p[2].dx, dx2 = p[3].dx - p[2].dx;
    final dy1 = p[1].dy - p[2].dy, dy2 = p[3].dy - p[2].dy;
    final sx = p[0].dx - p[1].dx + p[2].dx - p[3].dx;
    final sy = p[0].dy - p[1].dy + p[2].dy - p[3].dy;
    final den = dx1 * dy2 - dx2 * dy1;
    if (den.abs() < .0000001) throw ArgumentError('Surface is too narrow.');
    final g = (sx * dy2 - dx2 * sy) / den;
    final h = (dx1 * sy - sx * dy1) / den;
    return PerspectiveMapper._(
      p[1].dx - p[0].dx + g * p[1].dx,
      p[3].dx - p[0].dx + h * p[3].dx,
      p[0].dx,
      p[1].dy - p[0].dy + g * p[1].dy,
      p[3].dy - p[0].dy + h * p[3].dy,
      p[0].dy,
      g,
      h,
    );
  }
  Offset project(double u, double v) {
    final den = g * u + h * v + 1;
    return Offset((a * u + b * v + c) / den, (d * u + e * v + f) / den);
  }
}
