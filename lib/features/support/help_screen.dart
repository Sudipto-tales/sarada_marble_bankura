import 'package:flutter/material.dart';

import '../../core/config/app_config.dart';
import '../../core/routing/routes.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/widgets/app_image.dart';
import '../../core/widgets/feedback.dart';

class HelpScreen extends StatelessWidget {
  const HelpScreen({super.key});

  static const _faqs = [
    (
      'How is marble priced?',
      'By the square foot of finished slab. The price shown includes edge '
          'polishing on exposed sides. Cutting to size, installation and GST '
          'are quoted separately.'
    ),
    (
      'Will my slabs look exactly like the photos?',
      'No two slabs are identical — that is the nature of stone. Photos show a '
          'representative slab from the current lot. Request a sample for an '
          'accurate colour reference before large orders.'
    ),
    (
      'How much extra should I order?',
      'Plan for 8-10% wastage on straight layouts and 12-15% for diagonal or '
          'book-matched work. The in-app calculator applies this for you.'
    ),
    (
      'How are slabs delivered?',
      'Upright in A-frame crates on a 20-foot truck. Our crew assists with '
          'unloading; site access and a flat unloading area are required.'
    ),
    (
      'What if a slab arrives damaged?',
      'Report within 48 hours with photos. We replace from the same lot where '
          'possible, or refund in full.'
    ),
    (
      'Do you handle installation?',
      'We work with vetted fabricators in 40+ cities. Add installation in the '
          'calculator and our co-ordinator will arrange a site visit.'
    ),
    (
      'Can I return stone I no longer need?',
      'Uncut, undamaged slabs can be returned within 7 days of delivery. '
          'Cut-to-size and custom orders are non-returnable.'
    ),
  ];

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Scaffold(
      appBar: AppBar(title: const Text('Help centre')),
      body: ListView(
        padding: const EdgeInsets.only(bottom: AppDimens.xxl),
        children: [
          Container(
            margin: const EdgeInsets.all(AppDimens.lg),
            padding: const EdgeInsets.all(AppDimens.lg),
            decoration: BoxDecoration(
              gradient: AppColors.brandGradient,
              borderRadius: BorderRadius.circular(AppDimens.radiusLg),
            ),
            child: Row(
              children: [
                const Icon(Icons.support_agent_rounded,
                    size: 34, color: Colors.white),
                const SizedBox(width: AppDimens.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Talk to a stone advisor',
                        style: TextStyle(
                            color: Colors.white,
                            fontSize: 15.5,
                            fontWeight: FontWeight.w700),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        '${AppConfig.supportPhone} · Mon-Sat, 9am-7pm',
                        style: TextStyle(
                            color: Colors.white.withValues(alpha: 0.85),
                            fontSize: 12),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: AppDimens.screenPad,
            child: Row(
              children: [
                Expanded(
                  child: _Action(
                    icon: Icons.science_outlined,
                    label: 'Request sample',
                    onTap: () =>
                        Navigator.pushNamed(context, Routes.requestSample),
                  ),
                ),
                const SizedBox(width: AppDimens.md),
                Expanded(
                  child: _Action(
                    icon: Icons.request_quote_outlined,
                    label: 'Get a quote',
                    onTap: () =>
                        Navigator.pushNamed(context, Routes.requestQuote),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: AppDimens.lg),
          Padding(
            padding: AppDimens.screenPad,
            child: Text('Frequently asked', style: t.titleMedium),
          ),
          const SizedBox(height: AppDimens.sm),
          for (final (q, a) in _faqs)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppDimens.lg),
              child: Theme(
                data: Theme.of(context)
                    .copyWith(dividerColor: Colors.transparent),
                child: ExpansionTile(
                  tilePadding: EdgeInsets.zero,
                  childrenPadding: const EdgeInsets.only(bottom: AppDimens.md),
                  title: Text(q, style: t.titleSmall),
                  children: [
                    Align(
                      alignment: Alignment.centerLeft,
                      child: Text(a,
                          style: t.bodyMedium?.copyWith(
                              height: 1.5, color: AppColors.muted)),
                    ),
                  ],
                ),
              ),
            ),
          const SizedBox(height: AppDimens.lg),
          Padding(
            padding: AppDimens.screenPad,
            child: OutlinedButton.icon(
              onPressed: () => Toast.show(
                  context, 'Live chat is not part of this prototype build'),
              icon: const Icon(Icons.chat_bubble_outline_rounded, size: 18),
              label: const Text('Still stuck? Message us'),
            ),
          ),
        ],
      ),
    );
  }
}

class _Action extends StatelessWidget {
  const _Action({required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => InkWell(
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: AppDimens.lg),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surface,
            borderRadius: BorderRadius.circular(AppDimens.radiusMd),
            border: Border.all(color: AppColors.line),
          ),
          child: Column(
            children: [
              Icon(icon, size: 24, color: AppColors.deep),
              const SizedBox(height: 8),
              Text(label, style: Theme.of(context).textTheme.titleSmall),
            ],
          ),
        ),
      );
}

class AboutScreen extends StatelessWidget {
  const AboutScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Scaffold(
      appBar: AppBar(title: const Text('About')),
      body: ListView(
        padding: const EdgeInsets.all(AppDimens.xl),
        children: [
          Center(
            child: AppImage('assets/brand/logo_full.webp', width: 200),
          ),
          const SizedBox(height: AppDimens.xl),
          Text('${AppConfig.appName} · ${AppConfig.tagline}',
              textAlign: TextAlign.center, style: t.titleLarge),
          const SizedBox(height: AppDimens.md),
          Text(
            'We supply graded marble, granite and sanitation ware direct from '
            'quarries and importers to homes, hotels and commercial projects '
            'across India. Every slab is inspected before it is crated.',
            textAlign: TextAlign.center,
            style: t.bodyMedium?.copyWith(height: 1.6, color: AppColors.muted),
          ),
          const SizedBox(height: AppDimens.xxl),
          _Stat(value: '20+', label: 'Years in stone'),
          _Stat(value: '40+', label: 'Cities served'),
          _Stat(value: '8,500+', label: 'Projects supplied'),
          const SizedBox(height: AppDimens.xxl),
          Text(
            'Prototype build — static demo data, local media assets, no backend '
            'and no payment gateway.',
            textAlign: TextAlign.center,
            style: t.bodySmall,
          ),
        ],
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.value, required this.label});

  final String value;
  final String label;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppDimens.sm),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(value,
                style: Theme.of(context)
                    .textTheme
                    .headlineSmall
                    ?.copyWith(color: AppColors.deep)),
            const SizedBox(width: 10),
            Flexible(
              child: Text(
                label,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ),
          ],
        ),
      );
}
