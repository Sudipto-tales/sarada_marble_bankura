import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';

import '../../../core/widgets/app_image.dart';

class ProcessVideoCard extends StatelessWidget {
  const ProcessVideoCard({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 20),
      child: Material(
        color: theme.colorScheme.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(20),
          side: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => showDialog<void>(
            context: context,
            builder: (_) => const _ProcessVideoDialog(),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Stack(
                alignment: Alignment.center,
                children: [
                  const AspectRatio(
                    aspectRatio: 1186 / 544,
                    child: AppImage('assets/videos/tilesview-poster.jpg'),
                  ),
                  Positioned.fill(
                    child: ColoredBox(
                      color: Colors.black.withValues(alpha: .15),
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.all(14),
                    decoration: const BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                    ),
                    child: Icon(
                      Icons.play_arrow_rounded,
                      size: 32,
                      color: theme.colorScheme.primary,
                    ),
                  ),
                ],
              ),
              Padding(
                padding: const EdgeInsets.all(18),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Watch the process',
                      style: theme.textTheme.titleMedium,
                    ),
                    const SizedBox(height: 6),
                    Text(
                      'A quick look at room visualization · 6 sec',
                      style: theme.textTheme.bodySmall,
                    ),
                    const SizedBox(height: 14),
                    const Text(
                      'Upload a photo → Mark a surface → Try your stone',
                      style: TextStyle(fontSize: 12, height: 1.5),
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

class _ProcessVideoDialog extends StatefulWidget {
  const _ProcessVideoDialog();
  @override
  State<_ProcessVideoDialog> createState() => _ProcessVideoDialogState();
}

class _ProcessVideoDialogState extends State<_ProcessVideoDialog>
    with WidgetsBindingObserver {
  VideoPlayerController? _controller;
  bool _failed = false;
  bool _desktopPlaying = true;
  final bool _useAnimatedPreview =
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.linux ||
          defaultTargetPlatform == TargetPlatform.windows);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    if (!_useAnimatedPreview) _initialize();
  }

  Future<void> _initialize() async {
    final controller = VideoPlayerController.asset(
      'assets/videos/tilesview.mp4',
    );
    _controller = controller;
    try {
      await controller.initialize();
      if (!mounted) return;
      await controller.setLooping(true);
      if (!mounted) return;
      await controller.play();
      if (mounted) setState(() {});
    } catch (_) {
      if (mounted) setState(() => _failed = true);
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      _controller?.pause();
      if (mounted) setState(() => _desktopPlaying = false);
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Dialog(
    insetPadding: const EdgeInsets.all(16),
    clipBehavior: Clip.antiAlias,
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 900),
      child: SingleChildScrollView(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'Room visualization · How it works',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                  ),
                  IconButton(
                    tooltip: 'Close video',
                    onPressed: () => Navigator.pop(context),
                    icon: const Icon(Icons.close),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              if (_useAnimatedPreview)
                Column(
                  children: [
                    AspectRatio(
                      aspectRatio: 1186 / 544,
                      child: Image.asset(
                        _desktopPlaying
                            ? 'assets/videos/tilesview-preview.gif'
                            : 'assets/videos/tilesview-poster.jpg',
                        fit: BoxFit.contain,
                      ),
                    ),
                    TextButton.icon(
                      onPressed: () =>
                          setState(() => _desktopPlaying = !_desktopPlaying),
                      icon: Icon(_desktopPlaying ? Icons.stop : Icons.replay),
                      label: Text(
                        _desktopPlaying ? 'Stop preview' : 'Replay preview',
                      ),
                    ),
                  ],
                )
              else if (_failed)
                const Padding(
                  padding: EdgeInsets.all(24),
                  child: Text(
                    'The video could not be played. Close and reopen to try again.',
                  ),
                )
              else if (_controller?.value.isInitialized != true)
                const Padding(
                  padding: EdgeInsets.all(40),
                  child: CircularProgressIndicator(
                    semanticsLabel: 'Loading process video',
                  ),
                )
              else
                ValueListenableBuilder<VideoPlayerValue>(
                  valueListenable: _controller!,
                  builder: (context, value, _) => Column(
                    children: [
                      if (value.hasError)
                        const Text(
                          'Playback was interrupted. Close and reopen to try again.',
                        )
                      else
                        AspectRatio(
                          aspectRatio: value.aspectRatio,
                          child: Stack(
                            alignment: Alignment.center,
                            children: [
                              VideoPlayer(_controller!),
                              if (value.isBuffering)
                                const CircularProgressIndicator(
                                  semanticsLabel: 'Buffering video',
                                ),
                            ],
                          ),
                        ),
                      VideoProgressIndicator(
                        _controller!,
                        allowScrubbing: true,
                        padding: const EdgeInsets.symmetric(vertical: 12),
                      ),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          IconButton(
                            tooltip: value.isPlaying
                                ? 'Pause video'
                                : 'Play video',
                            onPressed: () => value.isPlaying
                                ? _controller!.pause()
                                : _controller!.play(),
                            icon: Icon(
                              value.isPlaying
                                  ? Icons.pause_rounded
                                  : Icons.play_arrow_rounded,
                            ),
                          ),
                          IconButton(
                            tooltip: value.volume > 0
                                ? 'Mute video'
                                : 'Unmute video',
                            onPressed: () => _controller!.setVolume(
                              value.volume > 0 ? 0 : 1,
                            ),
                            icon: Icon(
                              value.volume > 0
                                  ? Icons.volume_up_outlined
                                  : Icons.volume_off_outlined,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              const SizedBox(height: 8),
              const Text(
                'Choose a photo, mark the floor or wall, then explore your favourite finish.',
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    ),
  );
}
