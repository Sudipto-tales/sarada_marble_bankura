import 'package:flutter/material.dart';

import '../../../core/routing/routes.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/widgets/stone_motion.dart';
import '../../../core/widgets/app_image.dart';
import '../../../data/models/product.dart';

/// Swipeable gallery with thumbnails; tapping opens the full-screen viewer.
class ProductGallery extends StatefulWidget {
  const ProductGallery({super.key, required this.product});

  final Product product;

  @override
  State<ProductGallery> createState() => _ProductGalleryState();
}

class _ProductGalleryState extends State<ProductGallery> {
  final _controller = PageController();
  int _index = 0;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final images = widget.product.gallery.isEmpty
        ? [widget.product.image]
        : widget.product.gallery;
    return Stack(
      fit: StackFit.expand,
      children: [
        PageView.builder(
          controller: _controller,
          itemCount: images.length,
          onPageChanged: (i) => setState(() => _index = i),
          itemBuilder: (context, i) => GestureDetector(
            onTap: () => Navigator.pushNamed(
              context,
              Routes.gallery,
              arguments: GalleryArgs(
                images: images,
                initialIndex: i,
                title: widget.product.name,
              ),
            ),
            child: Container(
              margin: EdgeInsets.fromLTRB(
                16,
                MediaQuery.paddingOf(context).top + 56,
                16,
                32,
              ),
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: stoneTint(
                  widget.product.color,
                  Theme.of(context).brightness,
                ),
                borderRadius: AppDimens.stoneCurve,
              ),
              child: ClipRRect(
                borderRadius: AppDimens.stoneCurve,
                child: AppImage(images[i], fit: BoxFit.cover),
              ),
            ),
          ),
        ),
        Positioned(
          left: 0,
          right: 0,
          bottom: 12,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              for (var i = 0; i < images.length; i++)
                AnimatedContainer(
                  duration: const Duration(milliseconds: 220),
                  margin: const EdgeInsets.symmetric(horizontal: 3),
                  width: i == _index ? 16 : 6,
                  height: 6,
                  decoration: BoxDecoration(
                    color: i == _index
                        ? AppColors.teal
                        : Colors.white.withValues(alpha: 0.7),
                    borderRadius: BorderRadius.circular(3),
                  ),
                ),
            ],
          ),
        ),
        Positioned(
          right: 12,
          bottom: 12,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
            decoration: BoxDecoration(
              color: AppColors.ink.withValues(alpha: 0.55),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Text(
              '${_index + 1}/${images.length}',
              style: const TextStyle(
                color: Colors.white,
                fontSize: 11,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Full-screen pinch-zoom viewer.
class GalleryScreen extends StatefulWidget {
  const GalleryScreen({super.key, required this.args});

  final GalleryArgs args;

  @override
  State<GalleryScreen> createState() => _GalleryScreenState();
}

class _GalleryScreenState extends State<GalleryScreen> {
  late final PageController _controller = PageController(
    initialPage: widget.args.initialIndex,
  );
  late int _index = widget.args.initialIndex;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.ink,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        foregroundColor: Colors.white,
        title: Text(
          widget.args.title ?? 'Gallery',
          style: const TextStyle(color: Colors.white),
        ),
        actions: [
          Center(
            child: Padding(
              padding: const EdgeInsets.only(right: 16),
              child: Text(
                '${_index + 1} / ${widget.args.images.length}',
                style: const TextStyle(color: Colors.white70, fontSize: 12.5),
              ),
            ),
          ),
        ],
      ),
      body: PageView.builder(
        controller: _controller,
        itemCount: widget.args.images.length,
        onPageChanged: (i) => setState(() => _index = i),
        itemBuilder: (context, i) => InteractiveViewer(
          minScale: 1,
          maxScale: 4,
          child: Center(
            child: AppImage(
              widget.args.images[i],
              fit: BoxFit.contain,
              placeholderColor: AppColors.ink,
            ),
          ),
        ),
      ),
    );
  }
}
