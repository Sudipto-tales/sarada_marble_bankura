import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/app_image.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/room.dart';
import '../../data/models/saved_design.dart';

/// Designs saved from the 3D room. Part of the visualization module.
class SavedDesignsScreen extends StatefulWidget {
  const SavedDesignsScreen({super.key});

  @override
  State<SavedDesignsScreen> createState() => _SavedDesignsScreenState();
}

class _SavedDesignsScreenState extends State<SavedDesignsScreen> {
  late Future<(List<SavedDesign>, List<Room>)> _future = _load();

  Future<(List<SavedDesign>, List<Room>)> _load() async {
    final repo = AppScope.read(context).rooms;
    return (await repo.savedDesigns(), await repo.all());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Saved designs')),
      body: FutureBuilder<(List<SavedDesign>, List<Room>)>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const LoadingView();
          }
          if (snap.hasError) {
            return ErrorView(onRetry: () => setState(() => _future = _load()));
          }
          final (designs, rooms) = snap.data!;
          if (designs.isEmpty) {
            return EmptyView(
              icon: Icons.bookmark_border_rounded,
              title: 'No saved designs',
              message:
                  'Open a room in the 3D preview, apply marble and save the combination.',
              actionLabel: 'Open 3D rooms',
              onAction: () => Navigator.pushNamed(context, Routes.visualizer,
                  arguments: const VisualizerArgs()),
            );
          }
          return ListView.builder(
            padding: const EdgeInsets.only(bottom: AppDimens.xl),
            itemCount: designs.length,
            itemBuilder: (context, i) {
              final design = designs[i];
              final room =
                  rooms.where((r) => r.id == design.roomId).firstOrNull;
              return Container(
                margin: const EdgeInsets.fromLTRB(
                    AppDimens.lg, AppDimens.md, AppDimens.lg, 0),
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.surface,
                  borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                  border: Border.all(color: AppColors.line),
                ),
                child: InkWell(
                  borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                  onTap: room == null
                      ? null
                      : () => Navigator.pushNamed(context, Routes.visualizer,
                          arguments: VisualizerArgs(roomId: room.id)),
                  child: Padding(
                    padding: const EdgeInsets.all(AppDimens.md),
                    child: Row(
                      children: [
                        if (room != null)
                          AppImage(room.thumb,
                              width: 76, height: 62, radius: AppDimens.radiusSm),
                        const SizedBox(width: AppDimens.md),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(design.name,
                                  style:
                                      Theme.of(context).textTheme.titleSmall),
                              const SizedBox(height: 2),
                              Text(
                                '${room?.name ?? 'Room'} · '
                                '${Fmt.plural(design.assignments.length, 'surface')}',
                                style: Theme.of(context).textTheme.bodySmall,
                              ),
                              const SizedBox(height: 2),
                              Text(Fmt.relative(design.createdOn),
                                  style:
                                      Theme.of(context).textTheme.labelSmall),
                            ],
                          ),
                        ),
                        IconButton(
                          onPressed: () async {
                            final ok = await confirmDialog(
                              context,
                              title: 'Delete design?',
                              message: design.name,
                              confirmLabel: 'Delete',
                              destructive: true,
                            );
                            if (!ok || !context.mounted) return;
                            await AppScope.read(context)
                                .rooms
                                .deleteDesign(design.id);
                            if (context.mounted) {
                              setState(() => _future = _load());
                            }
                          },
                          icon: const Icon(Icons.delete_outline_rounded,
                              size: 20, color: AppColors.danger),
                        ),
                      ],
                    ),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
