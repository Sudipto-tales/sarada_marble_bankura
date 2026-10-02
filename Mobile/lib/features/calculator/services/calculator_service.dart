import 'dart:math' as math;

import '../../../core/bridge/module_bridge.dart';
import '../models/calculation.dart';

/// All calculator maths lives here — never in a widget.
class CalculatorService {
  const CalculatorService();

  static const double defaultPolishingRate = 28; // ₹ per sq.ft
  static const double defaultInstallationRate = 65; // ₹ per sq.ft
  static const double gstPercent = 18;

  double netArea(List<AreaEntry> entries) =>
      entries.fold(0.0, (sum, e) => sum + e.areaSqFt);

  Estimate estimate({
    required List<AreaEntry> entries,
    required double pricePerSqFt,
    required double wastagePercent,
    double slabSqFt = 31,
    bool includePolishing = false,
    bool includeInstallation = false,
    double polishingRate = defaultPolishingRate,
    double installationRate = defaultInstallationRate,
  }) {
    final net = netArea(entries);
    final total = net * (1 + wastagePercent / 100);
    final slabs = slabSqFt <= 0 ? 0 : (total / slabSqFt).ceil();
    final material = total * pricePerSqFt;
    final polishing = includePolishing ? total * polishingRate : 0.0;
    final installation = includeInstallation ? net * installationRate : 0.0;
    final tax = (material + polishing + installation) * gstPercent / 100;

    return Estimate(
      netAreaSqFt: net,
      wastagePercent: wastagePercent,
      totalAreaSqFt: total,
      slabCount: slabs,
      materialCost: material,
      polishingCost: polishing,
      installationCost: installation,
      tax: tax,
    );
  }

  /// Suggested wastage: more cuts (many small surfaces) means more waste.
  double suggestedWastage(List<AreaEntry> entries) {
    if (entries.isEmpty) return 8;
    final avg = netArea(entries) / entries.length;
    if (avg < 60) return 12;
    if (avg < 200) return 10;
    return 8;
  }

  /// The only thing the e-commerce module ever receives from this module.
  CalculationResult toResult(String productId, Estimate estimate) =>
      CalculationResult(
        productId: productId,
        requiredSqFt: double.parse(estimate.totalAreaSqFt.toStringAsFixed(1)),
        estimatedCost: estimate.grandTotal,
        slabCount: estimate.slabCount,
        wastagePercent: estimate.wastagePercent,
      );

  /// Rough per-room presets so a first-time user gets a sensible starting point.
  List<AreaEntry> presetFor(String kind) => switch (kind) {
        'Bedroom' => const [
            AreaEntry(id: 'e1', label: 'Floor', length: 14, width: 12),
          ],
        'Kitchen' => const [
            AreaEntry(id: 'e1', label: 'Counter top', length: 10, width: 2),
            AreaEntry(id: 'e2', label: 'Backsplash', length: 10, width: 1.5),
          ],
        'Bathroom' => const [
            AreaEntry(id: 'e1', label: 'Floor', length: 8, width: 6),
            AreaEntry(id: 'e2', label: 'Walls', length: 8, width: 7, count: 2),
          ],
        'Staircase' => const [
            AreaEntry(id: 'e1', label: 'Treads', length: 3.5, width: 1, count: 16),
            AreaEntry(id: 'e2', label: 'Risers', length: 3.5, width: 0.6, count: 16),
          ],
        _ => const [
            AreaEntry(id: 'e1', label: 'Floor', length: 18, width: 14),
          ],
      };

  /// Slabs are sold whole; show how much extra area that forces the buyer into.
  double leftoverArea(Estimate estimate, double slabSqFt) =>
      math.max(estimate.slabCount * slabSqFt - estimate.totalAreaSqFt, 0);
}
