import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/filters.dart';
import '../../data/models/product.dart';
import '../catalog/widgets/product_card.dart';

/// Debounced search over the static catalog with history and suggestions.
class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key, this.initialQuery = ''});

  final String initialQuery;

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  late final TextEditingController _controller =
      TextEditingController(text: widget.initialQuery);
  final FocusNode _focus = FocusNode();
  Timer? _debounce;
  Future<List<Product>>? _future;
  String _query = '';

  static const _popular = [
    'Statuario',
    'Black granite',
    'Italian marble',
    'Makrana',
    'Kitchen counter',
    'Bathroom',
    'Onyx',
    'Beige',
  ];

  @override
  void initState() {
    super.initState();
    _query = widget.initialQuery;
    if (_query.isNotEmpty) _run(_query);
    WidgetsBinding.instance.addPostFrameCallback((_) => _focus.requestFocus());
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _controller.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _onChanged(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 280), () {
      setState(() {
        _query = value;
        _future = value.trim().isEmpty ? null : _search(value);
      });
    });
  }

  Future<List<Product>> _search(String value) => AppScope.read(context)
      .products
      .query(ProductFilter(query: value, sort: SortOption.relevance));

  void _run(String value) {
    _controller.text = value;
    _controller.selection =
        TextSelection.collapsed(offset: value.length);
    AppScope.read(context).browsing.recordSearch(value);
    setState(() {
      _query = value;
      _future = _search(value);
    });
    _focus.unfocus();
  }

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 0,
        title: Hero(
          tag: 'search-bar',
          child: Material(
            color: Colors.transparent,
            child: TextField(
              controller: _controller,
              focusNode: _focus,
              textInputAction: TextInputAction.search,
              onChanged: _onChanged,
              onSubmitted: _run,
              decoration: InputDecoration(
                hintText: 'Search marble, granite, colours…',
                filled: false,
                border: InputBorder.none,
                enabledBorder: InputBorder.none,
                focusedBorder: InputBorder.none,
                suffixIcon: _controller.text.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(Icons.close_rounded, size: 19),
                        onPressed: () {
                          _controller.clear();
                          setState(() {
                            _query = '';
                            _future = null;
                          });
                        },
                      ),
              ),
            ),
          ),
        ),
      ),
      body: _future == null
          ? _Suggestions(
              popular: _popular,
              history: deps.browsing.searchHistory,
              onTap: _run,
              onRemove: (term) {
                deps.browsing.removeSearch(term);
                setState(() {});
              },
              onClear: () {
                deps.browsing.clearSearches();
                setState(() {});
              },
            )
          : FutureBuilder<List<Product>>(
              future: _future,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const LoadingView();
                }
                if (snap.hasError) {
                  return ErrorView(
                      onRetry: () => setState(() => _future = _search(_query)));
                }
                final results = snap.data ?? const <Product>[];
                if (results.isEmpty) {
                  return EmptyView(
                    icon: Icons.search_off_rounded,
                    title: 'No matches for "$_query"',
                    message:
                        'Try a colour ("beige"), a place ("Makrana") or a use ("counter").',
                    actionLabel: 'Browse all marble',
                    onAction: () => Navigator.pushReplacementNamed(
                        context, Routes.catalog,
                        arguments: const CatalogArgs()),
                  );
                }
                return Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.symmetric(
                          horizontal: AppDimens.lg, vertical: AppDimens.sm),
                      child: Row(
                        children: [
                          Text(
                            '${Fmt.plural(results.length, 'result')} for "$_query"',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                          const Spacer(),
                          TextButton(
                            onPressed: () => Navigator.pushNamed(
                              context,
                              Routes.catalog,
                              arguments: CatalogArgs(
                                  query: _query, title: 'Results'),
                            ),
                            child: const Text('Filter & sort'),
                          ),
                        ],
                      ),
                    ),
                    Expanded(
                      child: ListView.separated(
                        itemCount: results.length,
                        separatorBuilder: (_, _) => const Divider(height: 1),
                        itemBuilder: (context, i) =>
                            ProductListTile(product: results[i]),
                      ),
                    ),
                  ],
                );
              },
            ),
    );
  }
}

class _Suggestions extends StatelessWidget {
  const _Suggestions({
    required this.popular,
    required this.history,
    required this.onTap,
    required this.onRemove,
    required this.onClear,
  });

  final List<String> popular;
  final List<String> history;
  final ValueChanged<String> onTap;
  final ValueChanged<String> onRemove;
  final VoidCallback onClear;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return ListView(
      padding: const EdgeInsets.all(AppDimens.lg),
      children: [
        if (history.isNotEmpty) ...[
          Row(
            children: [
              Text('Recent searches', style: t.titleSmall),
              const Spacer(),
              TextButton(onPressed: onClear, child: const Text('Clear')),
            ],
          ),
          const SizedBox(height: AppDimens.sm),
          for (final term in history)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.history_rounded,
                  size: 19, color: AppColors.muted),
              title: Text(term, style: t.bodyMedium),
              trailing: IconButton(
                icon: const Icon(Icons.close_rounded, size: 17),
                onPressed: () => onRemove(term),
              ),
              onTap: () => onTap(term),
            ),
          const SizedBox(height: AppDimens.lg),
        ],
        Text('Popular searches', style: t.titleSmall),
        const SizedBox(height: AppDimens.md),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final term in popular)
              ActionChip(
                label: Text(term),
                onPressed: () => onTap(term),
              ),
          ],
        ),
      ],
    );
  }
}
