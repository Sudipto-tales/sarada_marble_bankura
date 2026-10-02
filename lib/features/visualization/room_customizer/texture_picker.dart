import 'package:flutter/material.dart';

import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/app_image.dart';
import '../../../data/models/product.dart';

/// Marble swatch grid used inside the 3D room. Returns the chosen [Product];
/// the renderer resolves its texture through `textureId`.
class TexturePicker extends StatefulWidget {
  const TexturePicker({
    super.key,
    required this.products,
    required this.surfaceLabel,
    required this.areaSqFt,
    this.selectedId,
  });

  final List<Product> products;
  final String surfaceLabel;
  final double areaSqFt;
  final String? selectedId;

  @override
  State<TexturePicker> createState() => _TexturePickerState();
}

class _TexturePickerState extends State<TexturePicker> {
  String _query = '';
  String? _colorFilter;

  @override
  Widget build(BuildContext context) {
    final colors = widget.products.map((p) => p.color).toSet().toList()..sort();
    final list = widget.products.where((p) {
      if (_colorFilter != null && p.color != _colorFilter) return false;
      if (_query.trim().isEmpty) return true;
      return p.name.toLowerCase().contains(_query.toLowerCase().trim());
    }).toList();

    return DraggableScrollableSheet(
      initialChildSize: 0.72,
      minChildSize: 0.4,
      maxChildSize: 0.94,
      expand: false,
      builder: (context, controller) => Column(
        children: [
          const SizedBox(height: AppDimens.sm),
          Container(
            width: 42,
            height: 4,
            decoration: BoxDecoration(
              color: AppColors.line,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(
                AppDimens.lg, AppDimens.md, AppDimens.lg, AppDimens.sm),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Marble for ${widget.surfaceLabel.toLowerCase()}',
                          style: Theme.of(context).textTheme.titleLarge),
                      Text(
                        '${Fmt.sqft(widget.areaSqFt)} surface area',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                IconButton(
                  onPressed: () => Navigator.pop(context),
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppDimens.lg),
            child: TextField(
              onChanged: (v) => setState(() => _query = v),
              decoration: const InputDecoration(
                hintText: 'Search marble',
                prefixIcon: Icon(Icons.search_rounded, size: 20),
                isDense: true,
              ),
            ),
          ),
          SizedBox(
            height: 48,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(
                  horizontal: AppDimens.lg, vertical: AppDimens.sm),
              children: [
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: FilterChip(
                    label: const Text('All'),
                    selected: _colorFilter == null,
                    onSelected: (_) => setState(() => _colorFilter = null),
                  ),
                ),
                for (final c in colors)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: FilterChip(
                      label: Text(c),
                      selected: _colorFilter == c,
                      onSelected: (sel) =>
                          setState(() => _colorFilter = sel ? c : null),
                    ),
                  ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: list.isEmpty
                ? Center(
                    child: Text('No marble matches',
                        style: Theme.of(context).textTheme.bodyMedium),
                  )
                : GridView.builder(
                    controller: controller,
                    padding: const EdgeInsets.all(AppDimens.lg),
                    gridDelegate:
                        const SliverGridDelegateWithFixedCrossAxisCount(
                      crossAxisCount: 3,
                      mainAxisSpacing: AppDimens.md,
                      crossAxisSpacing: AppDimens.md,
                      childAspectRatio: 0.78,
                    ),
                    itemCount: list.length,
                    itemBuilder: (context, i) {
                      final p = list[i];
                      final selected = p.id == widget.selectedId;
                      return InkWell(
                        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
                        onTap: () => Navigator.pop(context, p),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Expanded(
                              child: Container(
                                decoration: BoxDecoration(
                                  borderRadius:
                                      BorderRadius.circular(AppDimens.radiusMd),
                                  border: Border.all(
                                    color: selected
                                        ? AppColors.teal
                                        : AppColors.line,
                                    width: selected ? 2.4 : 1,
                                  ),
                                ),
                                child: Stack(
                                  fit: StackFit.expand,
                                  children: [
                                    AppImage(
                                      'assets/textures/marble/${p.textureId}_thumb.webp',
                                      radius: AppDimens.radiusMd - 2,
                                    ),
                                    if (selected)
                                      Positioned(
                                        right: 5,
                                        top: 5,
                                        child: Container(
                                          padding: const EdgeInsets.all(3),
                                          decoration: const BoxDecoration(
                                            color: AppColors.teal,
                                            shape: BoxShape.circle,
                                          ),
                                          child: const Icon(Icons.check_rounded,
                                              size: 12, color: Colors.white),
                                        ),
                                      ),
                                  ],
                                ),
                              ),
                            ),
                            const SizedBox(height: 6),
                            Text(
                              p.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context).textTheme.labelMedium,
                            ),
                            Text(
                              '${Fmt.rupees(p.pricePerSqFt)}/sq.ft',
                              style: Theme.of(context)
                                  .textTheme
                                  .labelSmall
                                  ?.copyWith(color: AppColors.deep),
                            ),
                          ],
                        ),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}
