import 'dart:math' as math;

enum MeasureUnit { feet, metres, inches }

extension MeasureUnitInfo on MeasureUnit {
  String get label => switch (this) {
        MeasureUnit.feet => 'Feet',
        MeasureUnit.metres => 'Metres',
        MeasureUnit.inches => 'Inches',
      };

  String get short => switch (this) {
        MeasureUnit.feet => 'ft',
        MeasureUnit.metres => 'm',
        MeasureUnit.inches => 'in',
      };

  /// Multiplier to convert a length in this unit into feet.
  double get toFeet => switch (this) {
        MeasureUnit.feet => 1,
        MeasureUnit.metres => 3.28084,
        MeasureUnit.inches => 1 / 12,
      };
}

/// One measured surface (a floor, a wall, a counter run).
class AreaEntry {
  const AreaEntry({
    required this.id,
    required this.label,
    required this.length,
    required this.width,
    this.unit = MeasureUnit.feet,
    this.count = 1,
    this.deduction = 0,
  });

  final String id;
  final String label;
  final double length;
  final double width;
  final MeasureUnit unit;

  /// How many identical surfaces (e.g. 4 identical walls).
  final int count;

  /// Area to subtract in square feet — doors, windows, cut-outs.
  final double deduction;

  double get areaSqFt {
    final l = length * unit.toFeet;
    final w = width * unit.toFeet;
    return math.max(l * w * count - deduction, 0);
  }

  AreaEntry copyWith({
    String? label,
    double? length,
    double? width,
    MeasureUnit? unit,
    int? count,
    double? deduction,
  }) =>
      AreaEntry(
        id: id,
        label: label ?? this.label,
        length: length ?? this.length,
        width: width ?? this.width,
        unit: unit ?? this.unit,
        count: count ?? this.count,
        deduction: deduction ?? this.deduction,
      );
}

/// Fully resolved estimate. Plain data — the calculator screen renders it and
/// the bridge converts it into a CalculationResult for the shop.
class Estimate {
  const Estimate({
    required this.netAreaSqFt,
    required this.wastagePercent,
    required this.totalAreaSqFt,
    required this.slabCount,
    required this.materialCost,
    required this.polishingCost,
    required this.installationCost,
    required this.tax,
  });

  final double netAreaSqFt;
  final double wastagePercent;
  final double totalAreaSqFt;
  final int slabCount;
  final double materialCost;
  final double polishingCost;
  final double installationCost;
  final double tax;

  double get subtotal => materialCost + polishingCost + installationCost;
  double get grandTotal => subtotal + tax;
}
