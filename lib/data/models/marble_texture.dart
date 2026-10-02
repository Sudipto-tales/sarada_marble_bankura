/// Visualization-side asset descriptor. Products reference it only by id.
class MarbleTexture {
  const MarbleTexture({
    required this.id,
    required this.name,
    required this.asset,
    required this.thumb,
    required this.baseColor,
    this.glossiness = 0.6,
    this.tileMetres = 1.2,
  });

  final String id;
  final String name;
  final String asset;
  final String thumb;

  /// ARGB int, used as a placeholder tint while the tile decodes.
  final int baseColor;

  /// 0 = honed, 1 = mirror polish. Drives the renderer's reflection strength.
  final double glossiness;

  /// Real-world metres covered by one texture repeat.
  final double tileMetres;
}
