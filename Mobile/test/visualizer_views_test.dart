import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/core/routing/router.dart';
import 'package:maa_sarada/core/routing/routes.dart';
import 'package:maa_sarada/core/state/app_scope.dart';
import 'package:maa_sarada/core/store/local_store.dart';
import 'package:maa_sarada/core/theme/app_theme.dart';
import 'package:maa_sarada/data/models/photo_surface.dart';
import 'package:maa_sarada/data/models/saved_design.dart';
import 'package:maa_sarada/data/models/surface_style.dart';
import 'package:maa_sarada/data/repositories/static_repositories.dart';
import 'package:maa_sarada/features/visualization/photo/perspective_mapper.dart';
import 'package:maa_sarada/features/visualization/photo/photo_room_viewport.dart';
import 'package:maa_sarada/features/visualization/photo/photo_source_service.dart';
import 'package:maa_sarada/features/visualization/photo/photo_surface_editor.dart';
import 'package:maa_sarada/features/visualization/state/visualizer_controller.dart';

const corners = [
  Offset(.2, .2),
  Offset(.8, .3),
  Offset(.9, .9),
  Offset(.1, .8),
];

void main() {
  test(
    'perspective mapping hits all four corners and rejects crossed polygons',
    () {
      final mapper = PerspectiveMapper(corners);
      for (final pair in [
        ((0.0, 0.0), corners[0]),
        ((1.0, 0.0), corners[1]),
        ((1.0, 1.0), corners[2]),
        ((0.0, 1.0), corners[3]),
      ]) {
        expect(
          (mapper.project(pair.$1.$1, pair.$1.$2) - pair.$2).distance,
          lessThan(1e-8),
        );
      }
      expect(
        PerspectiveMapper.isValid([
          corners[0],
          corners[2],
          corners[1],
          corners[3],
        ]),
        isFalse,
      );
      expect(
        PerspectiveMapper.isValid([
          Offset.zero,
          Offset.zero,
          Offset.zero,
          Offset.zero,
        ]),
        isFalse,
      );
      expect(
        PerspectiveMapper.isValid([
          const Offset(double.nan, 0),
          ...corners.skip(1),
        ]),
        isFalse,
      );
    },
  );
  test(
    'legacy designs migrate; new styles, calibrated planes and cameras round trip',
    () {
      final old = SavedDesign.fromJson({
        'id': 'old',
        'roomId': 'luxury_living',
        'assignments': {'floor': 'p_statuario'},
      });
      expect(old.mode, 'threeD');
      expect(old.styles, isEmpty);
      final fresh = SavedDesign(
        id: 'new',
        name: 'Room',
        roomId: old.roomId,
        assignments: old.assignments,
        createdOn: DateTime(2026),
        mode: 'twoD',
        styles: const {
          'floor': SurfaceStyle(tileMetres: .6, rotation: 90, groutMm: 4),
        },
        photoSurfaces: {
          'floor': PhotoSurface(
            corners: corners,
            exclusions: [const Offset(.5, .5)],
            widthMetres: 3,
            heightMetres: 4,
          ),
        },
        camera: const {'yaw': 15},
      );
      final restored = SavedDesign.fromJson(fresh.toJson());
      expect(restored.mode, 'twoD');
      expect(restored.styles['floor']!.rotation, 90);
      expect(restored.photoSurfaces['floor']!.corners, corners);
      expect(restored.photoSurfaces['floor']!.exclusions, [
        const Offset(.5, .5),
      ]);
      expect(
        restored.photoSurfaces['floor']!.areaSqFt,
        closeTo(129.1668, .001),
      );
      expect(restored.camera['yaw'], 15);
      expect(PhotoSurface(corners: corners).areaSqFt, isNull);
    },
  );
  testWidgets(
    'materials survive mode switches, rapid changes, and saved-design reload',
    (tester) async {
      final repo = StaticRoomRepository(LocalStore.memory());
      final d = VisualizerController(
        rooms: repo,
        catalog: const StaticProductRepository(),
      );
      final restored = VisualizerController(
        rooms: repo,
        catalog: const StaticProductRepository(),
      );
      await tester.runAsync(() async {
        await d.load(roomId: 'luxury_living', productId: 'p_carrara_white');
        expect(d.error, isNull);
        d.setMode(VisualizerMode.twoD);
        d.setPhotoSurface(
          'floor',
          PhotoSurface(corners: corners, widthMetres: 3, heightMetres: 4),
        );
        await d.setStyle(
          'floor',
          const SurfaceStyle(tileMetres: .6, rotation: 90),
        );
        final p1 = d.products[1], p2 = d.products[2];
        await Future.wait([d.apply('floor', p1), d.apply('floor', p2)]);
        expect(d.productFor('floor')!.id, p2.id);
        expect(d.busy, isFalse);
        d.setMode(VisualizerMode.threeD);
        expect(d.assignments['floor'], p2.id);
        final allRooms = await repo.all();
        final otherRoom = allRooms.firstWhere((r) => r.id != d.room!.id);
        await d.switchRoom(otherRoom);
        expect(d.room!.id, otherRoom.id);
        expect(d.assignments['floor'], p2.id);
        d.setCamera(d.scene!.camera.copyWith(yaw: 18));
        d.setMode(VisualizerMode.twoD);
        final saved = d.saveAs('My design');
        await repo.saveDesign(saved);
        await restored.load(savedId: saved.id);
        expect(restored.mode, VisualizerMode.twoD);
        expect(restored.assignments['floor'], p2.id);
        expect(restored.styleFor('floor').tileMetres, .6);
        expect(restored.scene!.camera.yaw, 18);
        expect(restored.selectedArea, closeTo(129.1668, .001));
        await restored.compare(true);
        await restored.compare(false);
        expect(restored.assignments['floor'], p2.id);
        final bytes = await restored.photo!.toByteData(
          format: ui.ImageByteFormat.png,
        );
        final normalized = await PhotoSourceService.normalize(
          bytes!.buffer.asUint8List(),
        );
        await restored.importPhoto(normalized);
        expect(restored.canShow3D, isFalse);
        expect(restored.selectedArea, isNull);
        restored.setMode(VisualizerMode.threeD);
        expect(restored.mode, VisualizerMode.twoD);
        expect(restored.saveAs('Photo').photoPng, isNotNull);
      });
      await tester.pump();
      d.dispose();
      restored.dispose();
      await tester.pump();
    },
  );
  testWidgets(
    'photo overlay respects surface bounds, furniture and before view',
    (tester) async {
      await tester.runAsync(() async {
        Future<ui.Image> solid(Color color) async {
          final recorder = ui.PictureRecorder();
          Canvas(recorder).drawRect(
            const Rect.fromLTWH(0, 0, 128, 128),
            Paint()..color = color,
          );
          final picture = recorder.endRecording();
          try {
            return await picture.toImage(128, 128);
          } finally {
            picture.dispose();
          }
        }

        final photo = await solid(Colors.blue), tile = await solid(Colors.red);
        for (final original in [false, true]) {
          final recorder = ui.PictureRecorder();
          PhotoRoomPainter(
            photo: photo,
            patterns: {'floor': tile},
            styles: const {},
            original: original,
            surfaces: {
              'floor': PhotoSurface(
                corners: const [
                  Offset(.1, .1),
                  Offset(.9, .1),
                  Offset(.9, .9),
                  Offset(.1, .9),
                ],
                exclusions: const [Offset(.5, .5)],
              ),
            },
          ).paint(Canvas(recorder), const Size(128, 128));
          final picture = recorder.endRecording();
          final image = await picture.toImage(128, 128);
          final pixels = (await image.toByteData())!.buffer.asUint8List();
          List<int> rgb(int x, int y) =>
              pixels.sublist((y * 128 + x) * 4, (y * 128 + x) * 4 + 3);
          expect(rgb(3, 3), [33, 150, 243]);
          expect(rgb(64, 64), [33, 150, 243]);
          expect(rgb(30, 30), original ? [33, 150, 243] : [244, 67, 54]);
          image.dispose();
          picture.dispose();
        }
        photo.dispose();
        tile.dispose();
      });
    },
  );

  for (final scale in [1.0, 1.8]) {
    testWidgets('room views and photo editor fit 320px at text scale $scale', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(320, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final deps = AppDependencies.static(LocalStore.memory());
      await tester.pumpWidget(
        AppScope(
          deps: deps,
          child: MaterialApp(
            theme: AppTheme.light(),
            onGenerateRoute: AppRouter.generate,
            initialRoute: Routes.visualizer,
            onGenerateInitialRoutes: (_) => [
              AppRouter.generate(
                const RouteSettings(
                  name: Routes.visualizer,
                  arguments: VisualizerArgs(roomId: 'luxury_living'),
                ),
              ),
            ],
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: TextScaler.linear(scale)),
              child: child!,
            ),
          ),
        ),
      );
      for (
        var i = 0;
        i < 30 && find.text('Room Visualizer').evaluate().isEmpty;
        i++
      ) {
        await tester.pump(const Duration(milliseconds: 300));
        await tester.runAsync(
          () => Future<void>.delayed(const Duration(milliseconds: 100)),
        );
      }
      await tester.pump();
      expect(find.text('Room Visualizer'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.tap(find.text('2D View'));
      await tester.pumpAndSettle();
      expect(find.byType(PhotoRoomViewport), findsOneWidget);
      expect(find.text('Mark surface'), findsOneWidget);
      await tester.tap(find.text('Mark surface'));
      await tester.pumpAndSettle();
      expect(find.byType(PhotoSurfaceEditor), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.pageBack();
      await tester.pumpAndSettle();
      await tester.tap(find.text('3D View'));
      await tester.pumpAndSettle();
      expect(find.byType(PhotoRoomViewport), findsNothing);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
      deps.dispose();
    });
  }

  testWidgets(
    'room customizer supports room change and share modal sheets',
    (tester) async {
      final deps = AppDependencies.static(LocalStore.memory());
      await tester.pumpWidget(
        AppScope(
          deps: deps,
          child: MaterialApp(
            theme: AppTheme.light(),
            onGenerateRoute: AppRouter.generate,
            initialRoute: Routes.visualizer,
            onGenerateInitialRoutes: (_) => [
              AppRouter.generate(
                const RouteSettings(
                  name: Routes.visualizer,
                  arguments: VisualizerArgs(roomId: 'luxury_living'),
                ),
              ),
            ],
          ),
        ),
      );
      for (
        var i = 0;
        i < 30 && find.text('Room Visualizer').evaluate().isEmpty;
        i++
      ) {
        await tester.pump(const Duration(milliseconds: 300));
        await tester.runAsync(
          () => Future<void>.delayed(const Duration(milliseconds: 100)),
        );
      }
      await tester.pump();
      expect(find.text('Room Visualizer'), findsOneWidget);

      // Open change room sheet
      expect(find.text('Change'), findsOneWidget);
      await tester.tap(find.text('Change'));
      await tester.pumpAndSettle();
      expect(find.text('Change room'), findsOneWidget);
      // Select another room
      await tester.tap(find.byType(ListTile).at(1));
      await tester.pumpAndSettle();
      expect(find.text('Change room'), findsNothing);

      // Switch to 2D view and open share sheet
      await tester.tap(find.text('2D View'));
      await tester.pumpAndSettle();
      expect(find.byIcon(Icons.ios_share_rounded), findsOneWidget);
      await tester.tap(find.byIcon(Icons.ios_share_rounded));
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 300)),
      );
      await tester.pumpAndSettle();
      expect(find.text('Share this design'), findsOneWidget);
      expect(find.text('Share design'), findsOneWidget);
      await tester.tap(find.text('Share design'));
      await tester.pumpAndSettle();
      expect(find.text('Share this design'), findsNothing);

      await tester.pumpWidget(const SizedBox.shrink());
      deps.dispose();
    },
  );
}
