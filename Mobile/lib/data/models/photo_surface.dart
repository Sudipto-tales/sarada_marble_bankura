import 'dart:ui';

/// Coordinates are normalized against the uncropped source photograph.
class PhotoSurface {
  PhotoSurface({
    required List<Offset> corners,
    List<Offset> exclusions = const [],
    this.widthMetres,
    this.heightMetres,
  }) : corners = List.unmodifiable(corners),
       exclusions = List.unmodifiable(exclusions);
  final List<Offset> corners;
  // Brush stamps have a radius of 2.5% of the source image's shorter side.
  final List<Offset> exclusions;
  final double? widthMetres;
  final double? heightMetres;
  bool get calibrated => widthMetres != null && heightMetres != null;
  double? get areaSqFt =>
      calibrated ? widthMetres! * heightMetres! * 10.7639 : null;
  Map<String, dynamic> toJson() => {
    'corners': [
      for (final p in corners) [p.dx, p.dy],
    ],
    'exclusions': [
      for (final p in exclusions) [p.dx, p.dy],
    ],
    'widthMetres': widthMetres,
    'heightMetres': heightMetres,
  };
  factory PhotoSurface.fromJson(Map<String, dynamic> json) {
    List<Offset> points(Object? value) => (value as List? ?? [])
        .whereType<List>()
        .where((p) => p.length == 2 && p[0] is num && p[1] is num)
        .map(
          (p) => Offset(
            (p[0] as num).toDouble().clamp(0, 1),
            (p[1] as num).toDouble().clamp(0, 1),
          ),
        )
        .toList();
    double? dimension(Object? value) =>
        value is num && value.isFinite && value > 0 && value <= 100
        ? value.toDouble()
        : null;
    return PhotoSurface(
      corners: points(json['corners']),
      exclusions: points(json['exclusions']),
      widthMetres: dimension(json['widthMetres']),
      heightMetres: dimension(json['heightMetres']),
    );
  }
}
