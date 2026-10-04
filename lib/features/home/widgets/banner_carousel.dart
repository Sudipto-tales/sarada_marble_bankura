import 'dart:async';

import 'package:flutter/material.dart';

import '../../../core/routing/routes.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/widgets/app_image.dart';
import '../../../core/widgets/stone_motion.dart';
import '../../../data/models/coupon.dart';

/// Auto-advancing hero carousel with a page indicator.
class BannerCarousel extends StatefulWidget {
  const BannerCarousel({super.key, required this.banners});

  final List<PromoBanner> banners;

  @override
  State<BannerCarousel> createState() => _BannerCarouselState();
}

class _BannerCarouselState extends State<BannerCarousel> {
  final _controller = PageController(viewportFraction: 0.92);
  Timer? _timer;
  int _page = 0;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 5), (_) {
      if (!mounted ||
          !_controller.hasClients ||
          widget.banners.isEmpty ||
          !TickerMode.valuesOf(context).enabled ||
          MediaQuery.disableAnimationsOf(context)) {
        return;
      }
      final next = (_page + 1) % widget.banners.length;
      _controller.animateToPage(
        next,
        duration: const Duration(milliseconds: 550),
        curve: Curves.easeOutCubic,
      );
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (widget.banners.isEmpty) return const SizedBox.shrink();
    return Column(
      children: [
        SizedBox(
          height:
              236 +
              (MediaQuery.textScalerOf(context).scale(16) / 16 - 1).clamp(
                    0,
                    2,
                  ) *
                  150,
          child: PageView.builder(
            controller: _controller,
            itemCount: widget.banners.length,
            onPageChanged: (i) => setState(() => _page = i),
            itemBuilder: (context, i) {
              final b = widget.banners[i];
              return Padding(
                padding: const EdgeInsets.symmetric(horizontal: 6),
                child: _BannerCard(banner: b),
              );
            },
          ),
        ),
        const SizedBox(height: AppDimens.md),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            for (var i = 0; i < widget.banners.length; i++)
              AnimatedContainer(
                duration: const Duration(milliseconds: 250),
                margin: const EdgeInsets.symmetric(horizontal: 3),
                width: i == _page ? 18 : 6,
                height: 6,
                decoration: BoxDecoration(
                  color: i == _page ? AppColors.teal : AppColors.line,
                  borderRadius: BorderRadius.circular(3),
                ),
              ),
          ],
        ),
      ],
    );
  }
}

class _BannerCard extends StatelessWidget {
  const _BannerCard({required this.banner});

  final PromoBanner banner;

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        Positioned.fill(
          top: 12,
          child: ClipPath(
            clipper: _ShowroomCurve(),
            child: Container(color: AppColors.deep),
          ),
        ),
        Positioned(
          top: 32,
          left: 18,
          bottom: 30,
          right: 140,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Text(
                'CURATED BY NATURE',
                style: TextStyle(
                  fontSize: 9,
                  letterSpacing: 1.8,
                  color: AppColors.ice,
                ),
              ),
              const SizedBox(height: 10),
              Text(
                banner.title,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: 23,
                  height: 1.08,
                  fontFamily: 'serif',
                  color: Colors.white,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                banner.subtitle,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 11, color: AppColors.ice),
              ),
              const SizedBox(height: 12),
              _Cta(banner: banner),
            ],
          ),
        ),
        Positioned(
          right: 8,
          top: 0,
          width: 122,
          bottom: 26,
          child: FloatingStone(
            child: Transform.rotate(
              angle: 0.055,
              child: Container(
                decoration: BoxDecoration(
                  borderRadius: AppDimens.stoneCurve,
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.18),
                      blurRadius: 18,
                      offset: const Offset(-4, 10),
                    ),
                  ],
                ),
                child: ClipRRect(
                  borderRadius: AppDimens.stoneCurve,
                  child: AppImage(banner.image),
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _ShowroomCurve extends CustomClipper<Path> {
  @override
  Path getClip(Size size) {
    final w = size.width;
    final h = size.height;
    return Path()
      ..moveTo(32, 0)
      ..lineTo(w - 32, 0)
      ..quadraticBezierTo(w, 0, w, 32)
      ..lineTo(w, h - 44)
      ..quadraticBezierTo(w, h - 16, w - 30, h - 16)
      ..lineTo(w * 0.78, h - 16)
      ..quadraticBezierTo(w * 0.5, h + 12, w * 0.25, h - 16)
      ..lineTo(32, h - 16)
      ..quadraticBezierTo(0, h - 16, 0, h - 48)
      ..lineTo(0, 32)
      ..quadraticBezierTo(0, 0, 32, 0)
      ..close();
  }

  @override
  bool shouldReclip(_ShowroomCurve oldClipper) => false;
}

class _Cta extends StatelessWidget {
  const _Cta({required this.banner});

  final PromoBanner banner;

  void _go(BuildContext context) {
    if (banner.productId != null) {
      Navigator.pushNamed(
        context,
        Routes.productDetails,
        arguments: ProductArgs(banner.productId!),
      );
    } else if (banner.categoryId != null) {
      Navigator.pushNamed(
        context,
        Routes.catalog,
        arguments: CatalogArgs(categoryId: banner.categoryId),
      );
    } else {
      Navigator.pushNamed(
        context,
        Routes.visualizer,
        arguments: const VisualizerArgs(),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(AppDimens.radiusSm),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppDimens.radiusSm),
        onTap: () => _go(context),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Flexible(
                child: Text(
                  banner.ctaLabel,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: AppColors.ink,
                    fontWeight: FontWeight.w700,
                    fontSize: 12.5,
                  ),
                ),
              ),
              const SizedBox(width: 5),
              const Icon(
                Icons.arrow_forward_rounded,
                size: 15,
                color: AppColors.deep,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
