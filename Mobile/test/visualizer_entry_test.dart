import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/core/routing/routes.dart';
import 'package:maa_sarada/core/state/app_scope.dart';
import 'package:maa_sarada/core/store/local_store.dart';
import 'package:maa_sarada/core/theme/app_theme.dart';
import 'package:maa_sarada/features/visualization/visualizer_entry_screen.dart';
import 'package:maa_sarada/features/visualization/widgets/process_video.dart';

Future<void> tick(WidgetTester tester) async {
  for (var i = 0; i < 8; i++) {
    await tester.pump(const Duration(milliseconds: 200));
  }
}

Future<void> reveal(WidgetTester tester, Finder finder, double delta) async {
  for (var i = 0; i < 25 && finder.hitTestable().evaluate().isEmpty; i++) {
    await tester.drag(find.byType(ListView), Offset(0, -delta));
    await tester.pump(const Duration(milliseconds: 300));
  }
  expect(finder.hitTestable(), findsOneWidget);
}

void main() {
  for (final width in [320.0, 900.0]) {
    testWidgets('room sections route the selected mode and product at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 1000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      VisualizerArgs? opened;
      await tester.pumpWidget(
        AppScope(
          deps: AppDependencies.static(LocalStore.memory()),
          child: MaterialApp(
            theme: AppTheme.light(),
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: const TextScaler.linear(1.5)),
              child: child!,
            ),
            home: const VisualizerEntryScreen(productId: 'p_statuario'),
            onGenerateRoute: (settings) {
              opened = settings.arguments as VisualizerArgs;
              return MaterialPageRoute<void>(
                builder: (_) => const Scaffold(body: Text('Opened room')),
              );
            },
          ),
        ),
      );
      await tick(tester);
      await reveal(tester, find.text('Watch the process'), 200);
      expect(find.byType(ProcessVideoCard), findsOneWidget);
      expect(tester.takeException(), isNull);
      await reveal(tester, find.text('Luxury Living Room'), 250);
      await tester.tap(find.text('Luxury Living Room'));
      await tick(tester);
      expect(opened?.initialMode, 'twoD');
      expect(opened?.productId, 'p_statuario');
      expect(opened?.roomId, 'luxury_living');
      tester.state<NavigatorState>(find.byType(Navigator)).pop();
      await tick(tester);
      await reveal(tester, find.text('3D Rooms'), -250);
      await tester.tap(find.text('3D Rooms'));
      await tick(tester);
      expect(find.byType(ProcessVideoCard), findsNothing);
      await reveal(tester, find.text('All rooms'), 150);
      await tester.ensureVisible(find.text('Kitchen'));
      await tick(tester);
      await tester.tap(find.text('Kitchen'));
      await tick(tester);
      expect(find.text('Luxury Living Room'), findsNothing);
      await reveal(tester, find.text('Modern Kitchen'), 150);
      await tester.tap(find.text('Modern Kitchen'));
      await tick(tester);
      expect(opened?.initialMode, 'threeD');
      expect(opened?.productId, 'p_statuario');
      expect(opened?.roomId, 'modern_kitchen');
      expect(tester.takeException(), isNull);
    });
  }
}
