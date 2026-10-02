/// The ONLY contract between the optional modules (3D visualization,
/// calculator) and the e-commerce core.
///
/// Rules:
///  * Visualization emits a [VisualizationResult] — a product id, the surface
///    it was applied to and an estimated area. It never touches cart, orders
///    or repositories.
///  * Calculator emits a [CalculationResult] — a product id, required area and
///    an estimated cost. It never touches cart, orders or repositories.
///  * The e-commerce module consumes these plain objects and decides what to
///    do (add to cart, prefill quantity, request a quote). It knows nothing
///    about renderers, cameras, textures or formulas.
library;

class VisualizationResult {
  const VisualizationResult({
    required this.productId,
    required this.surfaceId,
    required this.estimatedSqFt,
    this.roomId,
    this.designId,
  });

  final String productId;
  final String surfaceId;
  final double estimatedSqFt;
  final String? roomId;
  final String? designId;

  @override
  String toString() =>
      'VisualizationResult($productId on $surfaceId, $estimatedSqFt sq.ft)';
}

class CalculationResult {
  const CalculationResult({
    required this.productId,
    required this.requiredSqFt,
    required this.estimatedCost,
    this.slabCount,
    this.wastagePercent,
  });

  final String productId;
  final double requiredSqFt;
  final double estimatedCost;
  final int? slabCount;
  final double? wastagePercent;

  @override
  String toString() =>
      'CalculationResult($productId, $requiredSqFt sq.ft, $estimatedCost)';
}

/// Callback types the host screens pass down into the optional modules.
typedef VisualizationHandoff = void Function(VisualizationResult result);
typedef CalculationHandoff = void Function(CalculationResult result);
