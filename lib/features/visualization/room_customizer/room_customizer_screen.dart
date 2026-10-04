import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';

import '../../../core/bridge/module_bridge.dart';
import '../../../core/routing/routes.dart';
import '../../../core/state/app_scope.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/feedback.dart';
import '../../../core/widgets/state_views.dart';
import '../../../data/models/photo_surface.dart';
import '../../../data/models/product.dart';
import '../../../data/models/room.dart';
import '../camera/camera_state.dart';
import '../photo/photo_room_viewport.dart';
import '../photo/photo_source_service.dart';
import '../photo/photo_surface_editor.dart';
import '../rooms/cuboid_room_renderer.dart';
import '../state/visualizer_controller.dart';
import 'texture_picker.dart';
import '../widgets/visualizer_loading.dart';

/// Both viewports consume one design; only the host handles shopping actions.
class RoomCustomizerScreen extends StatefulWidget {
  const RoomCustomizerScreen({super.key, required this.args, this.onUseDesign});
  final VisualizerArgs args;
  final Future<void> Function(VisualizationResult)? onUseDesign;
  @override
  State<RoomCustomizerScreen> createState() => _RoomCustomizerScreenState();
}

class _RoomCustomizerScreenState extends State<RoomCustomizerScreen> {
  final _renderer = CuboidRoomRenderer();
  final _photoKey = GlobalKey();
  final _photoTransform = TransformationController();
  VisualizerController? _design;
  bool _loading = true, _importing = false, _saving = false, _adding = false;
  String? _error;
  double _gestureFov = 74;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final deps = AppScope.read(context);
    _design?.removeListener(_changed);
    _design?.dispose();
    final design = VisualizerController(
      rooms: deps.rooms,
      catalog: deps.products,
    );
    _design = design;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      await design.load(
        roomId: widget.args.roomId,
        productId: widget.args.productId,
        savedId: widget.args.designId,
        initialMode: widget.args.initialMode,
        photoPngOverride: widget.args.photoPng,
      );
      if (!mounted) return;
      if (design.scene == null) throw StateError('No scene available.');
      await _renderer.load(design.scene!);
      if (!mounted) return;
      design.addListener(_changed);
      setState(() => _loading = false);
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = 'Could not open this room or saved design.';
        });
      }
    }
  }

  void _changed() {
    if (!mounted) return;
    final scene = _design?.scene;
    if (scene != null) _renderer.load(scene);
    setState(() {});
  }

  @override
  void dispose() {
    _design?.removeListener(_changed);
    _design?.dispose();
    _renderer.dispose();
    _photoTransform.dispose();
    super.dispose();
  }

  Future<void> _chooseStone() async {
    final d = _design!;
    final surfaceId = d.selectedSurface;
    final product = await showModalBottomSheet<Product>(
      context: context,
      isScrollControlled: true,
      builder: (_) => TexturePicker(
        products: d.products,
        selectedId: d.assignments[surfaceId],
        surfaceLabel: d.surface?.label ?? 'Surface',
        areaSqFt: d.selectedArea ?? 0,
      ),
    );
    if (!mounted || product == null) return;
    await d.apply(surfaceId, product);
  }

  Future<void> _editSurface() async {
    final d = _design!;
    if (d.photo == null) return;
    final id = d.selectedSurface;
    final result = await Navigator.push<PhotoSurface>(
      context,
      MaterialPageRoute(
        builder: (_) => PhotoSurfaceEditor(
          photo: d.photo!,
          label: d.surface?.label ?? 'Surface',
          initial: d.photoSurfaces[id],
        ),
      ),
    );
    if (mounted && result != null) d.setPhotoSurface(id, result);
  }

  Future<void> _importPhoto() async {
    if (_importing) return;
    if (_design!.photoSurfaces.isNotEmpty) {
      final ok = await confirmDialog(
        context,
        title: 'Replace room photo?',
        message:
            'Surface markings will be cleared. Save your current design first if you want to keep them.',
        confirmLabel: 'Replace',
      );
      if (!mounted || !ok) return;
    }
    setState(() => _importing = true);
    try {
      final bytes = await PhotoSourceService.choose();
      if (!mounted || bytes == null) return;
      await _design!.importPhoto(bytes);
      _photoTransform.value = Matrix4.identity();
    } catch (_) {
      if (mounted) {
        Toast.error(
          context,
          'Could not open the photo. Choose a JPG, PNG or WebP under 15 MB and 40 megapixels.',
        );
      }
    } finally {
      if (mounted) setState(() => _importing = false);
    }
  }

  Future<void> _presetPhoto() async {
    final ok = await confirmDialog(
      context,
      title: 'Return to the preset room?',
      message:
          'Your imported photo and surface markings will be replaced. Save first to keep this design.',
      confirmLabel: 'Use preset',
    );
    if (!mounted || !ok) return;
    try {
      await _design!.usePresetPhoto();
      _photoTransform.value = Matrix4.identity();
    } catch (_) {
      if (mounted) Toast.error(context, 'Could not load the preset photo.');
    }
  }

  void _resetView() {
    final d = _design!, room = _design!.room!;
    if (d.mode == VisualizerMode.twoD) {
      _photoTransform.value = Matrix4.identity();
      return;
    }
    d.setCamera(
      Camera3D(
        position: Vec3(0, room.eyeHeight, -room.depth / 2 + .55),
        yaw: room.defaultYaw,
        pitch: room.defaultPitch,
        fov: room.fov,
      ),
    );
  }

  Future<void> _save() async {
    if (_saving) return;
    var designName =
        '${_design!.imported ? 'My room' : _design!.room!.name} · ${Fmt.shortDate(DateTime.now())}';
    final name = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Save design'),
        content: TextFormField(
          initialValue: designName,
          onChanged: (value) => designName = value,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'Design name'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () {
              if (designName.trim().isNotEmpty) {
                Navigator.pop(ctx, designName.trim());
              }
            },
            child: const Text('Save'),
          ),
        ],
      ),
    );
    if (!mounted || name == null) return;
    setState(() => _saving = true);
    try {
      final saved = _design!.saveAs(name);
      await AppScope.read(context).rooms.saveDesign(saved);
      if (!mounted) return;
      _design!.designId = saved.id;
      Toast.success(
        context,
        'Design saved',
        actionLabel: 'View',
        onAction: () => Navigator.pushNamed(context, Routes.savedDesigns),
      );
    } catch (_) {
      if (mounted) {
        Toast.error(context, 'Could not save the design. Try again.');
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _preview() async {
    ui.Image? image;
    try {
      if (_design!.mode == VisualizerMode.twoD) {
        final boundary =
            _photoKey.currentContext?.findRenderObject()
                as RenderRepaintBoundary?;
        image = await boundary?.toImage(pixelRatio: 2);
      } else {
        image = await _renderer.snapshot();
      }
      if (!mounted || image == null) return;
      final png = await image.toByteData(format: ui.ImageByteFormat.png);
      if (!mounted || png == null) return;
      final bytes = png.buffer.asUint8List();
      await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        builder: (context) => SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  'Design preview',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 12),
                SizedBox(
                  height: MediaQuery.sizeOf(context).height * .55,
                  child: Image.memory(bytes, fit: BoxFit.contain),
                ),
                TextButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('Close'),
                ),
              ],
            ),
          ),
        ),
      );
    } catch (_) {
      if (mounted) {
        Toast.error(context, 'Could not capture the preview. Try again.');
      }
    } finally {
      image?.dispose();
    }
  }

  Future<void> _changeRoom() async {
    final deps = AppScope.read(context);
    final allRooms = await deps.rooms.all();
    if (!mounted) return;
    final selected = await showModalBottomSheet<Room>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
              child: Text(
                'Change room',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            Flexible(
              child: ListView.separated(
                shrinkWrap: true,
                itemCount: allRooms.length,
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  final r = allRooms[i];
                  final isCurrent = r.id == _design?.room?.id;
                  return ListTile(
                    leading: ClipRRect(
                      borderRadius: BorderRadius.circular(8),
                      child: Image.asset(
                        r.thumb,
                        width: 56,
                        height: 40,
                        fit: BoxFit.cover,
                      ),
                    ),
                    title: Text(r.name),
                    subtitle: Text('${r.type} · ${r.surfaces.length} surfaces'),
                    trailing: isCurrent
                        ? const Icon(Icons.check, color: AppColors.teal)
                        : null,
                    onTap: () => Navigator.pop(context, r),
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
    if (!mounted || selected == null) return;
    await _design?.switchRoom(selected);
    _resetView();
  }

  Future<void> _share() async {
    ui.Image? image;
    try {
      if (_design!.mode == VisualizerMode.twoD) {
        final boundary =
            _photoKey.currentContext?.findRenderObject()
                as RenderRepaintBoundary?;
        image = await boundary?.toImage(pixelRatio: 2);
      } else {
        image = await _renderer.snapshot();
      }
      if (!mounted || image == null) return;
      final png = await image.toByteData(format: ui.ImageByteFormat.png);
      if (!mounted || png == null) return;
      final bytes = png.buffer.asUint8List();
      final d = _design!;
      final summary = d.visibleAssignments.entries
          .map((e) {
            final p = d.productFor(e.key);
            final s = d.room?.surface(e.key);
            return p != null && s != null ? '${s.label}: ${p.name}' : null;
          })
          .whereType<String>()
          .join('\n');
      if (!mounted) return;
      await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (context) => SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Share this design',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 6),
                Text(
                  d.imported ? 'My room photo' : d.room!.name,
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                const SizedBox(height: 12),
                ClipRRect(
                  borderRadius: BorderRadius.circular(12),
                  child: SizedBox(
                    height: 200,
                    width: double.infinity,
                    child: Image.memory(bytes, fit: BoxFit.contain),
                  ),
                ),
                if (summary.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  Text(
                    'Materials applied:',
                    style: Theme.of(context).textTheme.labelMedium,
                  ),
                  const SizedBox(height: 4),
                  Text(summary, style: Theme.of(context).textTheme.bodySmall),
                ],
                const SizedBox(height: 16),
                FilledButton.icon(
                  onPressed: () {
                    Navigator.pop(context);
                    Toast.success(context, 'Design ready to share');
                  },
                  icon: const Icon(Icons.share_outlined),
                  label: const Text('Share design'),
                ),
              ],
            ),
          ),
        ),
      );
    } catch (_) {
      if (mounted) {
        Toast.error(context, 'Could not share the design. Try again.');
      }
    } finally {
      image?.dispose();
    }
  }

  Future<void> _useDesign() async {
    final d = _design!, area = _design!.selectedArea;
    final product = d.productFor(d.selectedSurface);
    if (_adding ||
        product == null ||
        area == null ||
        area <= 0 ||
        widget.onUseDesign == null) {
      return;
    }
    setState(() => _adding = true);
    try {
      await widget.onUseDesign!(
        VisualizationResult(
          productId: product.id,
          surfaceId: d.selectedSurface,
          estimatedSqFt: area,
          roomId: d.room!.id,
          designId: d.designId,
        ),
      );
      if (mounted) Toast.success(context, '${product.name} added to cart');
    } catch (_) {
      if (mounted) {
        Toast.error(context, 'Could not use this design. Try again.');
      }
    } finally {
      if (mounted) setState(() => _adding = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return Scaffold(
        appBar: AppBar(title: const Text('Preparing your room')),
        body: VisualizerLoading(
          label: widget.args.initialMode == 'twoD'
              ? 'Preparing your 2D canvas'
              : 'Preparing your 3D room',
        ),
      );
    }
    if (_error != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Room Visualizer')),
        body: ErrorView(title: _error!, onRetry: _load),
      );
    }
    final d = _design!, scheme = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Room Visualizer', overflow: TextOverflow.ellipsis),
        actions: [
          IconButton(
            tooltip: 'Preview design',
            onPressed: _preview,
            icon: const Icon(Icons.image_outlined),
          ),
          IconButton(
            tooltip: 'Share design',
            onPressed: _share,
            icon: const Icon(Icons.ios_share_rounded),
          ),
          IconButton(
            tooltip: 'Save design',
            onPressed: _saving ? null : _save,
            icon: const Icon(Icons.bookmark_add_outlined),
          ),
        ],
      ),
      body: SafeArea(
        top: false,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      d.imported ? 'My room photo' : d.room!.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                  ),
                  if (!d.imported)
                    TextButton(
                      onPressed: _changeRoom,
                      child: const Text('Change'),
                    ),
                  IconButton(
                    tooltip: 'Reset view',
                    onPressed: _resetView,
                    icon: const Icon(Icons.restart_alt, size: 20),
                  ),
                ],
              ),
            ),
            SegmentedButton<VisualizerMode>(
              segments: [
                ButtonSegment(
                  value: VisualizerMode.threeD,
                  label: const Text('3D View'),
                  icon: const Icon(Icons.view_in_ar_outlined),
                  enabled: d.canShow3D,
                ),
                const ButtonSegment(
                  value: VisualizerMode.twoD,
                  label: Text('2D View'),
                  icon: Icon(Icons.photo_outlined),
                ),
              ],
              selected: {d.mode},
              onSelectionChanged: (v) => d.setMode(v.single),
            ),
            if (d.imported)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  'Your photo is available in 2D.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            const SizedBox(height: 8),
            Expanded(
              child: ColoredBox(
                color: scheme.surfaceContainerHighest,
                child: d.mode == VisualizerMode.twoD
                    ? _photoViewport(d)
                    : _threeDViewport(d),
              ),
            ),
            if (d.error != null)
              Padding(
                padding: const EdgeInsets.all(8),
                child: Text(d.error!, style: TextStyle(color: scheme.error)),
              ),
            ConstrainedBox(
              constraints: BoxConstraints(
                maxHeight: MediaQuery.sizeOf(context).height * .4,
              ),
              child: SingleChildScrollView(child: _controls(d)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _photoViewport(VisualizerController d) {
    if (d.photo == null) return const Center(child: Text('Photo unavailable'));
    return PhotoRoomViewport(
      photo: d.photo!,
      surfaces: d.photoSurfaces,
      patterns: d.patterns,
      styles: {for (final id in d.photoSurfaces.keys) id: d.styleFor(id)},
      original: d.showOriginal,
      captureKey: _photoKey,
      transformationController: _photoTransform,
    );
  }

  Widget _threeDViewport(VisualizerController d) => LayoutBuilder(
    builder: (context, constraints) {
      final size = constraints.biggest;
      return GestureDetector(
        onDoubleTap: _resetView,
        onScaleStart: (_) => _gestureFov = d.scene!.camera.fov,
        onScaleUpdate: (details) {
          final camera = d.scene!.camera;
          d.setCamera(
            details.pointerCount < 2
                ? camera.rotatedBy(
                    -details.focalPointDelta.dx * .24,
                    details.focalPointDelta.dy * .18,
                  )
                : camera.copyWith(
                    fov: (_gestureFov / details.scale).clamp(
                      Camera3D.minFov,
                      Camera3D.maxFov,
                    ),
                  ),
          );
        },
        child: Stack(
          fit: StackFit.expand,
          children: [
            _renderer.build(context),
            for (final hotspot in d.room!.hotspots)
              if (d.room!.surface(hotspot.surfaceId)?.applyable == true)
                ..._hotspot(
                  d,
                  hotspot.surfaceId,
                  hotspot.label,
                  Vec3(hotspot.x, hotspot.y, hotspot.z),
                  size,
                ),
          ],
        ),
      );
    },
  );
  List<Widget> _hotspot(
    VisualizerController d,
    String id,
    String label,
    Vec3 position,
    Size size,
  ) {
    final p = d.scene!.camera.project(position, size);
    if (!p.visible ||
        p.offset.dx < 0 ||
        p.offset.dx > size.width ||
        p.offset.dy < 0 ||
        p.offset.dy > size.height) {
      return [];
    }
    return [
      Positioned(
        left: (p.offset.dx - 22).clamp(0, size.width - 44),
        top: (p.offset.dy - 22).clamp(
          0,
          (size.height - 44).clamp(0, double.infinity),
        ),
        child: IconButton.filledTonal(
          tooltip: label,
          onPressed: () => d.selectSurface(id),
          icon: Icon(
            d.selectedSurface == id
                ? Icons.check_circle
                : Icons.add_circle_outline,
          ),
        ),
      ),
    ];
  }

  Widget _controls(VisualizerController d) {
    final product = d.productFor(d.selectedSurface), area = d.selectedArea;
    return Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            height: 46,
            child: ListView(
              scrollDirection: Axis.horizontal,
              children: [
                for (final s in d.room!.surfaces)
                  if (s.applyable)
                    Padding(
                      padding: const EdgeInsets.only(right: 6),
                      child: ChoiceChip(
                        label: Text(s.label),
                        selected: d.selectedSurface == s.id,
                        onSelected: (_) => d.selectSurface(s.id),
                      ),
                    ),
              ],
            ),
          ),
          if (d.mode == VisualizerMode.twoD) ...[
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton.icon(
                  onPressed: _editSurface,
                  icon: const Icon(Icons.crop_free, size: 18),
                  label: Text(
                    d.photoSurfaces.containsKey(d.selectedSurface)
                        ? 'Edit surface'
                        : 'Mark surface',
                  ),
                ),
                TextButton.icon(
                  onPressed: _importing ? null : _importPhoto,
                  icon: const Icon(
                    Icons.add_photo_alternate_outlined,
                    size: 18,
                  ),
                  label: Text(_importing ? 'Opening…' : 'Upload photo'),
                ),
                if (d.imported)
                  TextButton(
                    onPressed: _presetPhoto,
                    child: const Text('Use preset photo'),
                  ),
              ],
            ),
            if (!d.photoSurfaces.containsKey(d.selectedSurface))
              const Text(
                'Mark this surface’s four corners to preview stone.',
                style: TextStyle(fontSize: 12),
              ),
          ],
          const SizedBox(height: 6),
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      product?.name ?? 'Choose a stone',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                    if (product != null)
                      Text(
                        product.brand,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    Text(
                      area == null
                          ? 'Visual preview · add dimensions for area'
                          : '${Fmt.sqft(area)} · measured/preset area',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
              Column(
                children: [
                  const Text('Before', style: TextStyle(fontSize: 11)),
                  Switch(value: d.showOriginal, onChanged: d.compare),
                ],
              ),
            ],
          ),
          Wrap(
            spacing: 8,
            children: [
              OutlinedButton(
                onPressed: _chooseStone,
                child: const Text('Change marble'),
              ),
              TextButton(
                onPressed: _patternSettings,
                child: const Text('Tile settings'),
              ),
              FilledButton(
                onPressed:
                    d.busy ||
                        _adding ||
                        area == null ||
                        area <= 0 ||
                        widget.onUseDesign == null
                    ? null
                    : _useDesign,
                child: Text(_adding ? 'Adding…' : 'Add to cart'),
              ),
            ],
          ),
          if (d.busy)
            const Text('Updating stone…', style: TextStyle(fontSize: 11)),
        ],
      ),
    );
  }

  Future<void> _patternSettings() async {
    final d = _design!, id = _design!.selectedSurface;
    var style = d.styleFor(id);
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Tile settings · ${d.room!.surface(id)?.label}',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 12),
                Text('Square tile: ${(style.tileMetres * 100).round()} cm'),
                Slider(
                  value: style.tileMetres.clamp(.3, 2),
                  min: .3,
                  max: 2,
                  divisions: 17,
                  onChanged: (v) =>
                      update(() => style = style.copyWith(tileMetres: v)),
                  onChangeEnd: (_) => d.setStyle(id, style),
                ),
                Wrap(
                  spacing: 8,
                  children: [
                    for (final angle in [0, 90, 180, 270])
                      ChoiceChip(
                        label: Text('$angle°'),
                        selected: style.rotation == angle,
                        onSelected: (_) {
                          update(() => style = style.copyWith(rotation: angle));
                          d.setStyle(id, style);
                        },
                      ),
                  ],
                ),
                const SizedBox(height: 12),
                Text('Grout: ${style.groutMm.round()} mm'),
                Slider(
                  value: style.groutMm,
                  min: 0,
                  max: 10,
                  divisions: 10,
                  onChanged: (v) =>
                      update(() => style = style.copyWith(groutMm: v)),
                  onChangeEnd: (_) => d.setStyle(id, style),
                ),
                Wrap(
                  spacing: 8,
                  children: [
                    for (final color in const {
                      0xFFD6D2CB: 'Warm',
                      0xFFF5F5F5: 'White',
                      0xFF555555: 'Dark',
                    }.entries)
                      ChoiceChip(
                        label: Text(color.value),
                        selected: style.groutColor == color.key,
                        onSelected: (_) {
                          update(
                            () => style = style.copyWith(groutColor: color.key),
                          );
                          d.setStyle(id, style);
                        },
                      ),
                  ],
                ),
                const SizedBox(height: 12),
                Align(
                  alignment: Alignment.centerRight,
                  child: FilledButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text('Done'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
