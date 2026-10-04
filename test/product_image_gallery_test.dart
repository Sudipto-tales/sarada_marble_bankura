import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/core/widgets/app_image.dart';
import 'package:maa_sarada/core/widgets/product_image_gallery.dart';

const photos = [
  'assets/images/products/carrara_white_main.webp',
  'assets/images/products/carrara_white_macro.webp',
  'assets/images/products/carrara_white_tile.webp',
];

Widget gallery({
  List<String> images = photos,
  bool enabled = true,
  bool reduce = false,
}) => MaterialApp(
  home: MediaQuery(
    data: MediaQueryData(disableAnimations: reduce),
    child: TickerMode(
      enabled: enabled,
      child: Center(
        child: SizedBox(
          width: 180,
          height: 150,
          child: ProductImageGallery(images: images),
        ),
      ),
    ),
  ),
);

void main() {
  testWidgets('fills frame and cycles within 1–3 seconds', (tester) async {
    await tester.pumpWidget(gallery());
    expect(tester.getSize(find.byType(AppImage)), const Size(180, 150));
    expect(tester.widget<AppImage>(find.byType(AppImage)).fit, BoxFit.cover);
    await tester.pump(const Duration(milliseconds: 999));
    expect(find.byKey(ValueKey(photos[1])), findsNothing);
    for (final next in [photos[1], photos[2], photos[0]]) {
      var elapsed = 0;
      while (find.byKey(ValueKey(next)).evaluate().isEmpty && elapsed < 3000) {
        await tester.pump(const Duration(milliseconds: 10));
        elapsed += 10;
      }
      expect(find.byKey(ValueKey(next)), findsOneWidget);
      await tester.pump(const Duration(milliseconds: 600));
      expect(find.byType(AppImage), findsOneWidget);
    }
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(seconds: 4));
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'single and duplicate photos stay static; empty galleries are safe',
    (tester) async {
      for (final images in [
        <String>[],
        [photos[0]],
        [photos[0], photos[0]],
      ]) {
        await tester.pumpWidget(gallery(images: images));
        await tester.pump(const Duration(seconds: 4));
        expect(
          find.byType(AppImage),
          images.isEmpty ? findsNothing : findsOneWidget,
        );
        expect(tester.takeException(), isNull);
      }
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets('inactive tabs and reduced motion suspend rotation', (
    tester,
  ) async {
    await tester.pumpWidget(gallery(enabled: false));
    await tester.pump(const Duration(seconds: 4));
    expect(find.byKey(ValueKey(photos[1])), findsNothing);
    await tester.pumpWidget(gallery(reduce: true));
    await tester.pump(const Duration(seconds: 4));
    expect(find.byKey(ValueKey(photos[1])), findsNothing);
    await tester.pumpWidget(gallery());
    for (
      var i = 0;
      i < 300 && find.byKey(ValueKey(photos[1])).evaluate().isEmpty;
      i++
    ) {
      await tester.pump(const Duration(milliseconds: 10));
    }
    expect(find.byKey(ValueKey(photos[1])), findsOneWidget);
    await tester.pumpWidget(gallery(images: [photos[2]]));
    await tester.pump(const Duration(seconds: 4));
    expect(find.byKey(ValueKey(photos[2])), findsOneWidget);
    expect(find.byType(AppImage), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('cards rotate on independent random schedules', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Row(
          children: [
            for (var i = 0; i < 6; i++)
              SizedBox(
                width: 100,
                height: 100,
                child: ProductImageGallery(images: photos),
              ),
          ],
        ),
      ),
    );
    var sawStaggeredChange = false;
    for (var i = 0; i < 300; i++) {
      await tester.pump(const Duration(milliseconds: 10));
      final changed = find.byKey(ValueKey(photos[1])).evaluate().length;
      if (changed > 0 && changed < 6) sawStaggeredChange = true;
    }
    expect(sawStaggeredChange, isTrue);
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
