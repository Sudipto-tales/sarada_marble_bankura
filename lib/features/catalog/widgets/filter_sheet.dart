import 'package:flutter/material.dart';

import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/utils/formatters.dart';
import '../../../data/models/category.dart';
import '../../../data/models/filters.dart';

/// Bottom sheet that edits a [ProductFilter] and returns the new value.
class FilterSheet extends StatefulWidget {
  const FilterSheet({
    super.key,
    required this.initial,
    required this.facets,
    required this.categories,
    required this.priceRange,
  });

  final ProductFilter initial;
  final Map<String, List<String>> facets;
  final List<Category> categories;
  final (double, double) priceRange;

  @override
  State<FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends State<FilterSheet> {
  late ProductFilter _filter = widget.initial;
  late RangeValues _price = RangeValues(
    widget.initial.minPrice ?? widget.priceRange.$1,
    widget.initial.maxPrice ?? widget.priceRange.$2,
  );

  void _toggle(Set<String> set, String value, ProductFilter Function(Set<String>) apply) {
    final next = Set<String>.from(set);
    if (!next.remove(value)) next.add(value);
    setState(() => _filter = apply(next));
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return DraggableScrollableSheet(
      initialChildSize: 0.82,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      expand: false,
      builder: (context, scrollController) => Column(
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
                AppDimens.lg, AppDimens.md, AppDimens.sm, 0),
            child: Row(
              children: [
                Text('Filters', style: t.titleLarge),
                const Spacer(),
                TextButton(
                  onPressed: () => setState(() {
                    _filter = _filter.cleared();
                    _price = RangeValues(
                        widget.priceRange.$1, widget.priceRange.$2);
                  }),
                  child: const Text('Reset'),
                ),
                IconButton(
                  onPressed: () => Navigator.pop(context),
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ),
          ),
          const Divider(),
          Expanded(
            child: ListView(
              controller: scrollController,
              padding: const EdgeInsets.fromLTRB(
                  AppDimens.lg, AppDimens.md, AppDimens.lg, AppDimens.xl),
              children: [
                _Group(
                  title: 'Price per sq.ft',
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      RangeSlider(
                        values: _price,
                        min: widget.priceRange.$1,
                        max: widget.priceRange.$2,
                        divisions: 24,
                        labels: RangeLabels(
                          Fmt.rupees(_price.start),
                          Fmt.rupees(_price.end),
                        ),
                        onChanged: (v) => setState(() {
                          _price = v;
                          _filter = _filter.copyWith(
                              minPrice: v.start, maxPrice: v.end);
                        }),
                      ),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 4),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text(Fmt.rupees(_price.start), style: t.labelMedium),
                            Text(Fmt.rupees(_price.end), style: t.labelMedium),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                _Group(
                  title: 'Category',
                  child: Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final c in widget.categories)
                        FilterChip(
                          label: Text(c.name),
                          selected: _filter.categoryIds.contains(c.id),
                          onSelected: (_) => _toggle(
                            _filter.categoryIds,
                            c.id,
                            (s) => _filter.copyWith(categoryIds: s),
                          ),
                        ),
                    ],
                  ),
                ),
                for (final entry in widget.facets.entries)
                  _Group(
                    title: _facetTitle(entry.key),
                    child: Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        for (final v in entry.value)
                          FilterChip(
                            label: Text(v),
                            selected: _selected(entry.key).contains(v),
                            onSelected: (_) => _toggleFacet(entry.key, v),
                          ),
                      ],
                    ),
                  ),
                _Group(
                  title: 'Customer rating',
                  child: Wrap(
                    spacing: 8,
                    children: [
                      for (final r in [3.0, 4.0, 4.5])
                        ChoiceChip(
                          label: Text('${r.toStringAsFixed(r % 1 == 0 ? 0 : 1)}★ & above'),
                          selected: _filter.minRating == r,
                          onSelected: (sel) => setState(() => _filter = sel
                              ? _filter.copyWith(minRating: r)
                              : _filter.copyWith(clearRating: true)),
                        ),
                    ],
                  ),
                ),
                SwitchListTile.adaptive(
                  contentPadding: EdgeInsets.zero,
                  value: _filter.inStockOnly,
                  onChanged: (v) =>
                      setState(() => _filter = _filter.copyWith(inStockOnly: v)),
                  title: const Text('In stock only'),
                ),
              ],
            ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.all(AppDimens.lg),
              child: Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () => Navigator.pop(context),
                      child: const Text('Cancel'),
                    ),
                  ),
                  const SizedBox(width: AppDimens.md),
                  Expanded(
                    flex: 2,
                    child: FilledButton(
                      onPressed: () => Navigator.pop(context, _filter),
                      child: Text(_filter.activeCount == 0
                          ? 'Apply'
                          : 'Apply ${_filter.activeCount} filters'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Set<String> _selected(String key) => switch (key) {
        'color' => _filter.colors,
        'finish' => _filter.finishes,
        'origin' => _filter.origins,
        _ => _filter.brands,
      };

  void _toggleFacet(String key, String value) {
    switch (key) {
      case 'color':
        _toggle(_filter.colors, value, (s) => _filter.copyWith(colors: s));
      case 'finish':
        _toggle(_filter.finishes, value, (s) => _filter.copyWith(finishes: s));
      case 'origin':
        _toggle(_filter.origins, value, (s) => _filter.copyWith(origins: s));
      default:
        _toggle(_filter.brands, value, (s) => _filter.copyWith(brands: s));
    }
  }

  static String _facetTitle(String key) => switch (key) {
        'color' => 'Colour',
        'finish' => 'Finish',
        'origin' => 'Origin',
        _ => 'Brand',
      };
}

class _Group extends StatelessWidget {
  const _Group({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppDimens.xl),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: AppDimens.md),
            child,
          ],
        ),
      );
}

/// Sort options as a compact modal list.
Future<SortOption?> showSortSheet(BuildContext context, SortOption current) {
  return showModalBottomSheet<SortOption>(
    context: context,
    builder: (context) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const SizedBox(height: AppDimens.md),
          Text('Sort by', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: AppDimens.sm),
          for (final option in SortOption.values)
            ListTile(
              title: Text(option.label),
              trailing: option == current
                  ? const Icon(Icons.check_rounded, color: AppColors.teal)
                  : null,
              onTap: () => Navigator.pop(context, option),
            ),
          const SizedBox(height: AppDimens.sm),
        ],
      ),
    ),
  );
}
