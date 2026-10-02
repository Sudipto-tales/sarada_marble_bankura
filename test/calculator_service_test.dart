import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/features/calculator/models/calculation.dart';
import 'package:maa_sarada/features/calculator/services/calculator_service.dart';

void main() {
  const service = CalculatorService();

  test('area entries convert units, repeat counts and subtract deductions', () {
    const feet = AreaEntry(id: 'a', label: 'Floor', length: 10, width: 10);
    expect(feet.areaSqFt, closeTo(100, 0.001));

    const metres = AreaEntry(
      id: 'b',
      label: 'Floor',
      length: 3,
      width: 3,
      unit: MeasureUnit.metres,
    );
    expect(metres.areaSqFt, closeTo(96.875, 0.05));

    const walls = AreaEntry(
      id: 'c',
      label: 'Walls',
      length: 10,
      width: 8,
      count: 4,
      deduction: 21,
    );
    expect(walls.areaSqFt, closeTo(299, 0.001));

    const overDeducted = AreaEntry(
      id: 'd',
      label: 'Nook',
      length: 2,
      width: 2,
      deduction: 50,
    );
    expect(overDeducted.areaSqFt, 0, reason: 'area never goes negative');
  });

  test('estimate applies wastage, rounds slabs up and taxes the whole job', () {
    final estimate = service.estimate(
      entries: const [AreaEntry(id: 'a', label: 'Floor', length: 20, width: 10)],
      pricePerSqFt: 100,
      wastagePercent: 10,
      slabSqFt: 31,
      includePolishing: true,
      includeInstallation: true,
    );

    expect(estimate.netAreaSqFt, closeTo(200, 0.001));
    expect(estimate.totalAreaSqFt, closeTo(220, 0.001));
    expect(estimate.slabCount, 8, reason: '220 / 31 = 7.09 -> 8 whole slabs');
    expect(estimate.materialCost, closeTo(22000, 0.001));
    // polishing bills the cut area, installation bills the finished area
    expect(estimate.polishingCost, closeTo(220 * 28, 0.001));
    expect(estimate.installationCost, closeTo(200 * 65, 0.001));
    expect(estimate.tax, closeTo((22000 + 6160 + 13000) * 0.18, 0.01));
  });

  test('optional line items stay out of the total when unticked', () {
    final estimate = service.estimate(
      entries: const [AreaEntry(id: 'a', label: 'Floor', length: 10, width: 10)],
      pricePerSqFt: 200,
      wastagePercent: 0,
    );
    expect(estimate.polishingCost, 0);
    expect(estimate.installationCost, 0);
    expect(estimate.materialCost, closeTo(20000, 0.001));
  });

  test('suggested wastage rises as surfaces get smaller and more numerous', () {
    expect(service.suggestedWastage(const []), 8);
    expect(
      service.suggestedWastage(
        const [AreaEntry(id: 'a', label: 'Sill', length: 4, width: 1)],
      ),
      12,
    );
    expect(
      service.suggestedWastage(
        const [AreaEntry(id: 'a', label: 'Floor', length: 20, width: 15)],
      ),
      8,
    );
  });

  test('toResult exposes only the bridge fields the shop is allowed to see', () {
    final estimate = service.estimate(
      entries: const [AreaEntry(id: 'a', label: 'Floor', length: 12, width: 12)],
      pricePerSqFt: 150,
      wastagePercent: 8,
    );
    final result = service.toResult('p_carrara_white', estimate);

    expect(result.productId, 'p_carrara_white');
    expect(result.requiredSqFt, closeTo(155.5, 0.05));
    expect(result.estimatedCost, estimate.grandTotal);
    expect(result.slabCount, estimate.slabCount);
    expect(result.wastagePercent, 8);
  });

  test('leftover area reports the stone a whole-slab purchase forces', () {
    final estimate = service.estimate(
      entries: const [AreaEntry(id: 'a', label: 'Floor', length: 10, width: 10)],
      pricePerSqFt: 100,
      wastagePercent: 0,
      slabSqFt: 31,
    );
    expect(estimate.slabCount, 4);
    expect(service.leftoverArea(estimate, 31), closeTo(24, 0.001));
  });

  test('every preset produces measurable surfaces', () {
    for (final kind in ['Bedroom', 'Kitchen', 'Bathroom', 'Staircase', 'Hall']) {
      final entries = service.presetFor(kind);
      expect(entries, isNotEmpty, reason: '$kind preset');
      expect(service.netArea(entries), greaterThan(0), reason: '$kind area');
    }
  });
}
