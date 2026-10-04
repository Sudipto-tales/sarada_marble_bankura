import 'dart:convert';

import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/widgets/app_image.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/room.dart';
import 'photo/photo_source_service.dart';
import 'widgets/process_video.dart';
import 'widgets/visualizer_loading.dart';

/// Room picker — the entry point of the visualization module.
class VisualizerEntryScreen extends StatefulWidget {
  const VisualizerEntryScreen({
    super.key,
    this.embedded = false,
    this.productId,
  });

  final bool embedded;
  final String? productId;

  @override
  State<VisualizerEntryScreen> createState() => _VisualizerEntryScreenState();
}

class _VisualizerEntryScreenState extends State<VisualizerEntryScreen>
    with AutomaticKeepAliveClientMixin {
  late Future<List<Room>> _future = AppScope.read(context).rooms.all();

  bool _twoD = true;
  String _roomType = 'All rooms';

  @override
  bool get wantKeepAlive => widget.embedded;

  @override
  Widget build(BuildContext context) {
    super.build(context);
    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: !widget.embedded,
        title: const Text('Room Visualizer'),
        actions: [
          IconButton(
            tooltip: 'Saved designs',
            onPressed: () => Navigator.pushNamed(context, Routes.savedDesigns),
            icon: const Icon(Icons.bookmark_border_rounded),
          ),
        ],
      ),
      body: FutureBuilder<List<Room>>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const VisualizerLoading(
              label: 'Preparing your room collection',
            );
          }
          if (snap.hasError) {
            return ErrorView(
              onRetry: () =>
                  setState(() => _future = AppScope.read(context).rooms.all()),
            );
          }
          final rooms = snap.data ?? const <Room>[];
          if (rooms.isEmpty) {
            return const EmptyView(
              icon: Icons.view_in_ar_outlined,
              title: 'No rooms available',
              message: 'Room presets could not be loaded.',
            );
          }
          final visibleRooms = rooms
              .where(
                (room) => _roomType == 'All rooms' || room.type == _roomType,
              )
              .toList();
          return Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 1100),
              child: ListView(
                padding: EdgeInsets.only(bottom: widget.embedded ? 120 : 32),
                children: [
                  const _IntroCard(),
                  Padding(
                    padding: const EdgeInsets.all(AppDimens.lg),
                    child: SegmentedButton<bool>(
                      segments: const [
                        ButtonSegment(
                          value: true,
                          icon: Icon(Icons.image_outlined),
                          label: Text('2D Images'),
                        ),
                        ButtonSegment(
                          value: false,
                          icon: Icon(Icons.view_in_ar_outlined),
                          label: Text('3D Rooms'),
                        ),
                      ],
                      selected: {_twoD},
                      onSelectionChanged: (value) =>
                          setState(() => _twoD = value.single),
                    ),
                  ),
                  if (_twoD) ...[
                    const ProcessVideoCard(),
                    _UploadPhotoTile(productId: widget.productId),
                  ] else
                    Padding(
                      padding: const EdgeInsets.symmetric(
                        horizontal: AppDimens.lg,
                      ),
                      child: Text(
                        'A new perspective on your space. Rotate a room, select a surface and explore stone finishes.',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                    ),
                  SectionHeader(
                    title: _twoD
                        ? 'Start with a room image'
                        : 'Explore a room in 3D',
                    subtitle: _twoD
                        ? 'Choose a space and try stone on its floor or walls'
                        : 'Choose your space. Make it your own.',
                  ),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
                    child: Row(
                      children: [
                        for (final type in [
                          'All rooms',
                          ...rooms.map((r) => r.type).toSet(),
                        ])
                          Padding(
                            padding: const EdgeInsets.only(right: 8),
                            child: ChoiceChip(
                              label: Text(type),
                              selected: _roomType == type,
                              onSelected: (_) =>
                                  setState(() => _roomType = type),
                            ),
                          ),
                      ],
                    ),
                  ),
                  LayoutBuilder(
                    builder: (context, constraints) {
                      final columns = constraints.maxWidth >= 760
                          ? 3
                          : constraints.maxWidth >= 520
                          ? 2
                          : 1;
                      final width =
                          (constraints.maxWidth - 40 - (columns - 1) * 16) /
                          columns;
                      return Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 20),
                        child: Wrap(
                          spacing: 16,
                          runSpacing: 16,
                          children: [
                            for (final room in visibleRooms)
                              SizedBox(
                                width: width,
                                child: _RoomTile(
                                  room: room,
                                  productId: widget.productId,
                                  twoD: _twoD,
                                ),
                              ),
                          ],
                        ),
                      );
                    },
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

class _IntroCard extends StatelessWidget {
  const _IntroCard();

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.fromLTRB(20, 12, 20, 0),
    clipBehavior: Clip.antiAlias,
    decoration: BoxDecoration(borderRadius: BorderRadius.circular(24)),
    child: Stack(
      children: [
        const Positioned.fill(
          child: AppImage('assets/images/rooms/luxury_living.webp'),
        ),
        const Positioned.fill(
          child: DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [Color(0xEE292D29), Color(0x88292D29)],
                begin: Alignment.centerLeft,
                end: Alignment.centerRight,
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.all(26),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'THE ROOM STUDIO',
                style: TextStyle(
                  color: Color(0xFFE4E8DA),
                  fontSize: 10,
                  letterSpacing: 2.5,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 16),
              const Text(
                'Your space.\nYour stone. Your style.',
                style: TextStyle(
                  color: Colors.white,
                  fontSize: 30,
                  height: 1.15,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 12),
              const Text(
                'See how your favourite finishes feel at home, before you decide.',
                style: TextStyle(color: Color(0xFFE4E8DA), height: 1.5),
              ),
              const SizedBox(height: 22),
              Wrap(
                spacing: 14,
                runSpacing: 8,
                children: [
                  for (final label in [
                    '01  Choose a room',
                    '02  Try a finish',
                    '03  Save your look',
                  ])
                    Text(
                      label,
                      style: const TextStyle(color: Colors.white, fontSize: 11),
                    ),
                ],
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

class _UploadPhotoTile extends StatefulWidget {
  const _UploadPhotoTile({this.productId});
  final String? productId;

  @override
  State<_UploadPhotoTile> createState() => _UploadPhotoTileState();
}

class _UploadPhotoTileState extends State<_UploadPhotoTile> {
  bool _importing = false;

  Future<void> _pickPhoto() async {
    if (_importing) return;
    setState(() => _importing = true);
    try {
      final bytes = await PhotoSourceService.choose();
      if (!mounted || bytes == null) return;
      final encoded = base64Encode(bytes);
      Navigator.pushNamed(
        context,
        Routes.visualizer,
        arguments: VisualizerArgs(
          productId: widget.productId,
          initialMode: 'twoD',
          photoPng: encoded,
        ),
      );
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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final primary = theme.colorScheme.primary;
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppDimens.lg,
        AppDimens.md,
        AppDimens.lg,
        0,
      ),
      child: Material(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          onTap: _importing ? null : _pickPhoto,
          child: Container(
            padding: const EdgeInsets.all(AppDimens.md),
            decoration: BoxDecoration(
              border: Border.all(
                color: primary.withValues(alpha: 0.3),
                width: 1.5,
              ),
              borderRadius: BorderRadius.circular(AppDimens.radiusMd),
            ),
            child: Row(
              children: [
                Container(
                  width: 50,
                  height: 50,
                  decoration: BoxDecoration(
                    color: primary.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(AppDimens.radiusSm),
                  ),
                  child: Icon(
                    Icons.add_photo_alternate_outlined,
                    color: primary,
                    size: 26,
                  ),
                ),
                const SizedBox(width: AppDimens.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _importing
                            ? 'Opening your photo…'
                            : 'Upload your room photo',
                        style: theme.textTheme.titleSmall,
                      ),
                      const SizedBox(height: 3),
                      Text(
                        'Mark floor or walls on your own photo to preview tiles',
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: AppDimens.sm),
                Icon(Icons.arrow_forward_ios_rounded, size: 16, color: primary),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _RoomTile extends StatelessWidget {
  const _RoomTile({required this.room, required this.twoD, this.productId});
  final Room room;
  final bool twoD;
  final String? productId;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: theme.colorScheme.surface,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(color: theme.colorScheme.outlineVariant),
      ),
      child: InkWell(
        onTap: () => Navigator.pushNamed(
          context,
          Routes.visualizer,
          arguments: VisualizerArgs(
            roomId: room.id,
            productId: productId,
            initialMode: twoD ? 'twoD' : 'threeD',
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Stack(
              children: [
                AspectRatio(aspectRatio: 1.65, child: AppImage(room.preview)),
                Positioned(
                  top: 12,
                  left: 12,
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 10,
                      vertical: 5,
                    ),
                    decoration: BoxDecoration(
                      color: const Color(0xDD292D29),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Text(
                      twoD ? '2D IMAGE' : '3D ROOM',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 10,
                        letterSpacing: 1,
                      ),
                    ),
                  ),
                ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(room.name, style: theme.textTheme.titleMedium),
                        const SizedBox(height: 5),
                        Text(
                          twoD
                              ? 'Preview finishes on a room photo'
                              : 'Rotate, explore & customise',
                          style: theme.textTheme.bodySmall,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  Icon(
                    Icons.arrow_outward_rounded,
                    color: theme.colorScheme.primary,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
