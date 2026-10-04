/// Square-tile settings shared by the photo and perspective renderers.
class SurfaceStyle {
  const SurfaceStyle({
    this.tileMetres = 1.2,
    this.rotation = 0,
    this.groutMm = 2,
    this.groutColor = 0xFFD6D2CB,
  });
  final double tileMetres;
  final int rotation;
  final double groutMm;
  final int groutColor;

  SurfaceStyle copyWith({
    double? tileMetres,
    int? rotation,
    double? groutMm,
    int? groutColor,
  }) => SurfaceStyle(
    tileMetres: tileMetres ?? this.tileMetres,
    rotation: rotation ?? this.rotation,
    groutMm: groutMm ?? this.groutMm,
    groutColor: groutColor ?? this.groutColor,
  );
  Map<String, dynamic> toJson() => {
    'tileMetres': tileMetres,
    'rotation': rotation,
    'groutMm': groutMm,
    'groutColor': groutColor,
  };
  factory SurfaceStyle.fromJson(Map<String, dynamic> json) => SurfaceStyle(
    tileMetres: ((json['tileMetres'] as num?)?.toDouble() ?? 1.2).clamp(.3, 2),
    rotation: ((json['rotation'] as num?)?.toInt() ?? 0) % 360,
    groutMm: ((json['groutMm'] as num?)?.toDouble() ?? 2).clamp(0, 10),
    groutColor: (json['groutColor'] as num?)?.toInt() ?? 0xFFD6D2CB,
  );
}
