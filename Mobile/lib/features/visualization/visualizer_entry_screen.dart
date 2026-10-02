import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/widgets/app_image.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/room.dart';

/// Room picker — the entry point of the visualization module.
class VisualizerEntryScreen extends StatefulWidget {
  const VisualizerEntryScreen({super.key, this.embedded = false, this.productId});

  final bool embedded;
  final String? productId;

  @override
  State<VisualizerEntryScreen> createState() => _VisualizerEntryScreenState();
}

class _VisualizerEntryScreenState extends State<VisualizerEntryScreen>
    with AutomaticKeepAliveClientMixin {
  late Future<List<Room>> _future = AppScope.read(context).rooms.all();

  @override
  bool get wantKeepAlive => widget.embedded;

  @override
  Widget build(BuildContext context) {
    super.build(context);
    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: !widget.embedded,
        title: const Text('3D Room Preview'),
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
            return const LoadingView(label: 'Preparing rooms…');
          }
          if (snap.hasError) {
            return ErrorView(
              onRetry: () => setState(
                  () => _future = AppScope.read(context).rooms.all()),
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
          return ListView(
            padding: const EdgeInsets.only(bottom: AppDimens.xxxl),
            children: [
              const _IntroCard(),
              const SectionHeader(
                title: 'Choose a room',
                subtitle: 'Look around, tap a surface, swap the stone',
              ),
              for (final room in rooms)
                _RoomTile(room: room, productId: widget.productId),
              const SizedBox(height: AppDimens.lg),
            ],
          );
        },
      ),
    );
  }
}

class _IntroCard extends StatelessWidget {
  const _IntroCard();

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(
          AppDimens.lg, AppDimens.lg, AppDimens.lg, 0),
      padding: const EdgeInsets.all(AppDimens.lg),
      decoration: BoxDecoration(
        gradient: AppColors.brandGradient,
        borderRadius: BorderRadius.circular(AppDimens.radiusLg),
      ),
      child: Row(
        children: [
          const Icon(Icons.threed_rotation_rounded,
              size: 34, color: Colors.white),
          const SizedBox(width: AppDimens.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'See the stone before you buy it',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 15.5,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  'Drag to look around · pinch to zoom · tap a surface to change its marble',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.86),
                    fontSize: 11.5,
                    height: 1.3,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _RoomTile extends StatelessWidget {
  const _RoomTile({required this.room, this.productId});

  final Room room;
  final String? productId;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
          AppDimens.lg, 0, AppDimens.lg, AppDimens.md),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        onTap: () => Navigator.pushNamed(
          context,
          Routes.visualizer,
          arguments: VisualizerArgs(roomId: room.id, productId: productId),
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(AppDimens.radiusMd),
          child: Stack(
            children: [
              SizedBox(
                height: 168,
                width: double.infinity,
                child: AppImage(room.preview),
              ),
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(gradient: AppColors.photoScrim),
                ),
              ),
              Positioned(
                left: AppDimens.lg,
                right: AppDimens.lg,
                bottom: AppDimens.md,
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            room.name,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 17,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            '${room.type} · ${room.surfaces.length} surfaces',
                            style: TextStyle(
                              color: Colors.white.withValues(alpha: 0.8),
                              fontSize: 11.5,
                            ),
                          ),
                        ],
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.all(9),
                      decoration: const BoxDecoration(
                        color: Colors.white,
                        shape: BoxShape.circle,
                      ),
                      child: const Icon(Icons.play_arrow_rounded,
                          size: 18, color: AppColors.deep),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
