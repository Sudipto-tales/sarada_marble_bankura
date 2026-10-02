import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../../../core/bridge/module_bridge.dart';
import '../../../core/routing/routes.dart';
import '../../../core/state/app_scope.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/brand_widgets.dart';
import '../../../core/widgets/feedback.dart';
import '../../../core/widgets/state_views.dart';
import '../../../data/models/cart_item.dart';
import '../../../data/models/marble_texture.dart';
import '../../../data/models/product.dart';
import '../../../data/models/room.dart';
import '../../../data/models/saved_design.dart';
import '../camera/camera_state.dart';
import '../rooms/cuboid_room_renderer.dart';
import '../rooms/room_scene_builder.dart';
import '../textures/texture_cache.dart';
import 'texture_picker.dart';

/// The immersive room. Look around, tap a surface, swap the marble, compare
/// before/after, then hand a [VisualizationResult] back to the shop.
///
/// This screen knows nothing about carts or orders: when the user acts on a
/// design it emits a result object and lets the host decide.
class RoomCustomizerScreen extends StatefulWidget {
  const RoomCustomizerScreen({super.key, required this.args});

  final VisualizerArgs args;

  @override
  State<RoomCustomizerScreen> createState() => _RoomCustomizerScreenState();
}

class _RoomCustomizerScreenState extends State<RoomCustomizerScreen> {
  final CuboidRoomRenderer _renderer = CuboidRoomRenderer();

  Room? _room;
  List<Product> _products = const [];
  Map<String, MarbleTexture> _textures = const {};
  Object? _error;
  bool _loading = true;

  /// surfaceId -> productId, plus the untouched baseline for before/after.
  final Map<String, String> _applied = {};
  final Map<String, String> _original = {};

  String _selectedSurface = 'floor';
  bool _showOriginal = false;
  bool _immersive = false;
  Camera3D? _camera;
  double _fovAtGestureStart = 74;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _renderer.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final deps = AppScope.read(context);
      final rooms = await deps.rooms.all();
      final room = rooms.firstWhere(
        (r) => r.id == (widget.args.roomId ?? rooms.first.id),
        orElse: () => rooms.first,
      );
      final textures = await deps.rooms.textures();
      final products = await deps.products.all();
      final byTexture = {for (final t in textures) t.id: t};

      _applied.clear();
      _original.clear();
      for (final surface in room.surfaces) {
        final product = products
            .where((p) => p.textureId == surface.defaultTextureId)
            .firstOrNull;
        if (product != null) {
          _applied[surface.id] = product.id;
          _original[surface.id] = product.id;
        }
      }

      // Product handed in from a details screen lands on the floor first.
      final incoming = widget.args.productId;
      if (incoming != null) {
        _applied['floor'] = incoming;
      }

      final scene = await RoomSceneBuilder.build(
        room,
        {
          for (final entry in _applied.entries)
            if (byTexture[_textureIdOf(entry.value, products)] != null)
              entry.key: byTexture[_textureIdOf(entry.value, products)]!,
        },
      );
      await _renderer.load(scene);

      if (!mounted) return;
      setState(() {
        _room = room;
        _products = products;
        _textures = byTexture;
        _camera = scene.camera;
        _selectedSurface = room.surfaces.first.id;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e;
        _loading = false;
      });
    }
  }

  String _textureIdOf(String productId, [List<Product>? products]) {
    final list = products ?? _products;
    return list.where((p) => p.id == productId).firstOrNull?.textureId ?? '';
  }

  Product? _productFor(String surfaceId) {
    final id = (_showOriginal ? _original : _applied)[surfaceId];
    if (id == null) return null;
    return _products.where((p) => p.id == id).firstOrNull;
  }

  RoomSurface? get _surface =>
      _room?.surfaces.where((s) => s.id == _selectedSurface).firstOrNull;

  Future<void> _apply(String surfaceId, Product product) async {
    final texture = _textures[product.textureId];
    if (texture == null) return;
    final image = await TextureCache.instance.load(texture.asset);
    if (!mounted) return;
    setState(() {
      _applied[surfaceId] = product.id;
      _showOriginal = false;
    });
    _renderer.applyTexture(surfaceId, image,
        tint: Color(texture.baseColor), gloss: texture.glossiness);
  }

  Future<void> _toggleOriginal(bool value) async {
    setState(() => _showOriginal = value);
    final source = value ? _original : _applied;
    for (final entry in source.entries) {
      final product = _products.where((p) => p.id == entry.value).firstOrNull;
      if (product == null) continue;
      final texture = _textures[product.textureId];
      if (texture == null) continue;
      final image = await TextureCache.instance.load(texture.asset);
      if (!mounted) return;
      _renderer.applyTexture(entry.key, image,
          tint: Color(texture.baseColor), gloss: texture.glossiness);
    }
  }

  void _look(Offset delta) {
    final camera = _camera;
    if (camera == null) return;
    final next = camera.rotatedBy(-delta.dx * 0.24, delta.dy * 0.18);
    setState(() => _camera = next);
    _renderer.setCamera(next);
  }

  void _resetCamera() {
    final room = _room;
    if (room == null) return;
    final next = Camera3D(
      position: Vec3(0, room.eyeHeight, -room.depth / 2 + 0.55),
      yaw: room.defaultYaw,
      pitch: room.defaultPitch,
      fov: room.fov,
    );
    setState(() => _camera = next);
    _renderer.setCamera(next);
  }

  /// The single hand-off point back to the e-commerce module.
  VisualizationResult _result(String surfaceId, Product product) {
    final surface = _room?.surfaces.where((s) => s.id == surfaceId).firstOrNull;
    return VisualizationResult(
      productId: product.id,
      surfaceId: surfaceId,
      estimatedSqFt: surface?.areaSqFt ?? 0,
      roomId: _room?.id,
    );
  }

  Future<void> _addToCart() async {
    final product = _productFor(_selectedSurface);
    if (product == null) return;
    final result = _result(_selectedSurface, product);
    final deps = AppScope.read(context);
    await deps.cart.setQuantity(
      product,
      result.estimatedSqFt,
      source: CartSource.visualizer,
      note: '${_room?.name} · ${_surface?.label}',
    );
    if (!mounted) return;
    Toast.success(
      context,
      '${product.name} · ${Fmt.sqft(result.estimatedSqFt)} added',
      actionLabel: 'View cart',
      onAction: () => Navigator.pushNamed(context, Routes.cart),
    );
  }

  Future<void> _saveDesign() async {
    final room = _room;
    if (room == null) return;
    final controller = TextEditingController(
        text: '${room.name} · ${Fmt.shortDate(DateTime.now())}');
    final name = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Save this design'),
        content: TextField(
          controller: controller,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'Design name'),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: const Text('Save'),
          ),
        ],
      ),
    );
    if (name == null || name.isEmpty || !mounted) return;
    await AppScope.read(context).rooms.saveDesign(SavedDesign(
          id: 'd_${DateTime.now().millisecondsSinceEpoch}',
          name: name,
          roomId: room.id,
          assignments: Map.of(_applied),
          createdOn: DateTime.now(),
        ));
    if (!mounted) return;
    Toast.success(context, 'Design saved',
        actionLabel: 'View',
        onAction: () => Navigator.pushNamed(context, Routes.savedDesigns));
  }

  Future<void> _share() async {
    final image = await _renderer.snapshot();
    if (!mounted) return;
    final summary = _applied.entries
        .map((e) {
          final product = _products.where((p) => p.id == e.value).firstOrNull;
          final label = _room?.surfaces
              .where((s) => s.id == e.key)
              .firstOrNull
              ?.label;
          return product == null ? null : '$label: ${product.name}';
        })
        .whereType<String>()
        .join('\n');
    showModalBottomSheet<void>(
      context: context,
      builder: (context) => _ShareSheet(
        title: _room?.name ?? 'Room design',
        body: summary,
        snapshot: image,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: LoadingView(label: 'Building the room…'));
    }
    if (_error != null || _room == null) {
      return Scaffold(
        appBar: AppBar(),
        body: ErrorView(
          onRetry: _load,
          title: 'Could not open the 3D room',
          message: 'The room preset failed to load. Browsing and ordering are unaffected.',
        ),
      );
    }

    final room = _room!;
    final selectedProduct = _productFor(_selectedSurface);

    return Scaffold(
      backgroundColor: AppColors.ink,
      body: Stack(
        fit: StackFit.expand,
        children: [
          _viewport(room),
          _topBar(room),
          if (!_immersive) _bottomPanel(room, selectedProduct),
          if (_immersive)
            Positioned(
              right: AppDimens.lg,
              bottom: AppDimens.xl,
              child: FloatingActionButton.small(
                heroTag: 'exit-immersive',
                backgroundColor: Colors.white,
                onPressed: () => setState(() => _immersive = false),
                child: const Icon(Icons.close_fullscreen_rounded,
                    color: AppColors.ink),
              ),
            ),
        ],
      ),
    );
  }

  Widget _viewport(Room room) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final size = Size(constraints.maxWidth, constraints.maxHeight);
        return GestureDetector(
          behavior: HitTestBehavior.opaque,
          onDoubleTap: _resetCamera,
          onScaleStart: (_) => _fovAtGestureStart = _camera?.fov ?? room.fov,
          onScaleUpdate: (d) {
            if (d.pointerCount < 2) {
              _look(d.focalPointDelta);
              return;
            }
            final camera = _camera;
            if (camera == null) return;
            final next = camera.copyWith(
                fov: (_fovAtGestureStart / d.scale)
                    .clamp(Camera3D.minFov, Camera3D.maxFov));
            setState(() => _camera = next);
            _renderer.setCamera(next);
          },
          child: Stack(
            fit: StackFit.expand,
            children: [
              _renderer.build(context),
              ..._hotspots(room, size),
              IgnorePointer(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors: [
                        AppColors.ink.withValues(alpha: 0.55),
                        Colors.transparent,
                        Colors.transparent,
                        AppColors.ink.withValues(alpha: _immersive ? 0.2 : 0.72),
                      ],
                      stops: const [0, 0.22, 0.62, 1],
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                    ),
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  List<Widget> _hotspots(Room room, Size size) {
    final camera = _camera;
    if (camera == null) return const [];
    final widgets = <Widget>[];
    for (final hotspot in room.hotspots) {
      final surface =
          room.surfaces.where((s) => s.id == hotspot.surfaceId).firstOrNull;
      if (surface == null || !surface.applyable) continue;
      final p = camera.project(
          Vec3(hotspot.x, hotspot.y, hotspot.z), size);
      if (!p.visible) continue;
      if (p.offset.dx < -40 ||
          p.offset.dy < -40 ||
          p.offset.dx > size.width + 40 ||
          p.offset.dy > size.height + 40) {
        continue;
      }
      final selected = _selectedSurface == hotspot.surfaceId;
      final scale = (2.6 / math.max(p.depth, 1.2)).clamp(0.7, 1.25);
      widgets.add(Positioned(
        left: p.offset.dx - 60 * scale,
        top: p.offset.dy - 20 * scale,
        child: Transform.scale(
          scale: scale,
          child: _HotspotMarker(
            label: hotspot.label,
            selected: selected,
            onTap: () {
              setState(() => _selectedSurface = hotspot.surfaceId);
              _openPicker();
            },
          ),
        ),
      ));
    }
    return widgets;
  }

  Widget _topBar(Room room) {
    return Positioned(
      top: 0,
      left: 0,
      right: 0,
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(
              horizontal: AppDimens.sm, vertical: AppDimens.sm),
          child: Row(
            children: [
              _GlassButton(
                icon: Icons.arrow_back_rounded,
                onTap: () => Navigator.maybePop(context),
              ),
              const SizedBox(width: AppDimens.sm),
              if (!_immersive)
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        room.name,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      Text(
                        'Drag to look · pinch to zoom · double-tap to reset',
                        style: TextStyle(
                          color: Colors.white.withValues(alpha: 0.66),
                          fontSize: 10.5,
                        ),
                      ),
                    ],
                  ),
                )
              else
                const Spacer(),
              _GlassButton(
                icon: _immersive
                    ? Icons.fullscreen_exit_rounded
                    : Icons.fullscreen_rounded,
                onTap: () => setState(() => _immersive = !_immersive),
              ),
              const SizedBox(width: AppDimens.sm),
              _GlassButton(icon: Icons.ios_share_rounded, onTap: _share),
              const SizedBox(width: AppDimens.sm),
              _GlassButton(
                  icon: Icons.bookmark_add_outlined, onTap: _saveDesign),
            ],
          ),
        ),
      ),
    );
  }

  Widget _bottomPanel(Room room, Product? product) {
    return Positioned(
      left: 0,
      right: 0,
      bottom: 0,
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              height: 42,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: AppDimens.lg),
                children: [
                  for (final surface in room.surfaces)
                    if (surface.applyable)
                      Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          label: Text(surface.label),
                          selected: _selectedSurface == surface.id,
                          backgroundColor: Colors.white24,
                          selectedColor: Colors.white,
                          labelStyle: TextStyle(
                            color: _selectedSurface == surface.id
                                ? AppColors.ink
                                : Colors.white,
                            fontWeight: FontWeight.w600,
                            fontSize: 12,
                          ),
                          onSelected: (_) =>
                              setState(() => _selectedSurface = surface.id),
                        ),
                      ),
                ],
              ),
            ),
            const SizedBox(height: AppDimens.md),
            Container(
              margin: const EdgeInsets.symmetric(horizontal: AppDimens.md),
              padding: const EdgeInsets.all(AppDimens.md),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(AppDimens.radiusLg),
              ),
              child: Column(
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _surface?.label ?? 'Surface',
                              style: Theme.of(context).textTheme.labelSmall,
                            ),
                            Text(
                              product?.name ?? 'No marble applied',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context)
                                  .textTheme
                                  .titleMedium
                                  ?.copyWith(color: AppColors.ink),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              '${Fmt.sqft(_surface?.areaSqFt ?? 0)} · '
                              '${product == null ? '—' : Fmt.rupees(product.pricePerSqFt * (_surface?.areaSqFt ?? 0))} est.',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ],
                        ),
                      ),
                      Column(
                        children: [
                          Text('Before/after',
                              style: Theme.of(context).textTheme.labelSmall),
                          Switch.adaptive(
                            value: _showOriginal,
                            onChanged: _toggleOriginal,
                          ),
                        ],
                      ),
                    ],
                  ),
                  const SizedBox(height: AppDimens.sm),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: _openPicker,
                          icon: const Icon(Icons.grid_view_rounded, size: 17),
                          label: const Text('Change marble'),
                        ),
                      ),
                      const SizedBox(width: AppDimens.sm),
                      Expanded(
                        child: GradientButton(
                          label: 'Add to cart',
                          icon: Icons.shopping_cart_rounded,
                          height: 50,
                          onPressed: product == null ? null : _addToCart,
                        ),
                      ),
                    ],
                  ),
                  if (product != null) ...[
                    const SizedBox(height: 6),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: TextButton(
                        onPressed: () => Navigator.pushNamed(
                          context,
                          Routes.productDetails,
                          arguments: ProductArgs(product.id),
                        ),
                        child: const Text('View product details'),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: AppDimens.sm),
          ],
        ),
      ),
    );
  }

  Future<void> _openPicker() async {
    final selected = await showModalBottomSheet<Product>(
      context: context,
      isScrollControlled: true,
      builder: (context) => TexturePicker(
        products: _products,
        selectedId: _applied[_selectedSurface],
        surfaceLabel: _surface?.label ?? 'Surface',
        areaSqFt: _surface?.areaSqFt ?? 0,
      ),
    );
    if (selected != null) await _apply(_selectedSurface, selected);
  }
}

class _HotspotMarker extends StatelessWidget {
  const _HotspotMarker({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
          color: selected
              ? Colors.white
              : AppColors.ink.withValues(alpha: 0.62),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(
            color: selected ? AppColors.teal : Colors.white54,
            width: 1.2,
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              selected ? Icons.check_circle_rounded : Icons.add_circle_outline,
              size: 13,
              color: selected ? AppColors.teal : Colors.white,
            ),
            const SizedBox(width: 5),
            Text(
              label,
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w700,
                color: selected ? AppColors.ink : Colors.white,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _GlassButton extends StatelessWidget {
  const _GlassButton({required this.icon, required this.onTap});

  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
        color: AppColors.ink.withValues(alpha: 0.5),
        shape: const CircleBorder(),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(9),
            child: Icon(icon, size: 19, color: Colors.white),
          ),
        ),
      );
}

class _ShareSheet extends StatelessWidget {
  const _ShareSheet({
    required this.title,
    required this.body,
    required this.snapshot,
  });

  final String title;
  final String body;
  final ui.Image? snapshot;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(AppDimens.lg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Share this design',
                style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: AppDimens.sm),
            Text(title, style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: AppDimens.sm),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(AppDimens.md),
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surface,
                borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                border: Border.all(color: AppColors.line),
              ),
              child: Text(body.isEmpty ? 'No marble applied yet' : body,
                  style: Theme.of(context).textTheme.bodyMedium),
            ),
            const SizedBox(height: AppDimens.md),
            Text(
              'Sharing is disabled in this prototype build — the design summary '
              'above is what would be sent.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: AppDimens.lg),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Close'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
