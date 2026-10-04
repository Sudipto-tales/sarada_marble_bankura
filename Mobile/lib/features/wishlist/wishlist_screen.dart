import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/product.dart';
import '../catalog/widgets/product_card.dart';

class WishlistScreen extends StatefulWidget {
  const WishlistScreen({super.key});

  @override
  State<WishlistScreen> createState() => _WishlistScreenState();
}

class _WishlistScreenState extends State<WishlistScreen> {
  late Future<List<Product>> _future = _load();

  Future<List<Product>> _load() {
    final deps = AppScope.read(context);
    return deps.products.byIds(deps.wishlist.ids);
  }

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Wishlist'),
        actions: [
          Observer(
            listenable: deps.wishlist,
            builder: (context, wishlist) => wishlist.count == 0
                ? const SizedBox.shrink()
                : TextButton(
                    onPressed: () async {
                      final ok = await confirmDialog(
                        context,
                        title: 'Clear wishlist?',
                        message: 'This removes all saved marble.',
                        confirmLabel: 'Clear',
                        destructive: true,
                      );
                      if (ok) {
                        wishlist.clear();
                        setState(() => _future = _load());
                      }
                    },
                    child: const Text('Clear'),
                  ),
          ),
        ],
      ),
      body: Observer(
        listenable: deps.wishlist,
        builder: (context, wishlist) {
          if (wishlist.count == 0) {
            return EmptyView(
              icon: Icons.favorite_border_rounded,
              title: 'Nothing saved yet',
              message: 'Tap the heart on any marble to keep it here for later.',
              actionLabel: 'Browse marble',
              onAction: () => Navigator.pushNamed(
                context,
                Routes.catalog,
                arguments: const CatalogArgs(),
              ),
            );
          }
          return FutureBuilder<List<Product>>(
            future: _future,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) {
                return const ProductGridSkeleton();
              }
              if (snap.hasError) {
                return ErrorView(
                  onRetry: () => setState(() => _future = _load()),
                );
              }
              final saved = (snap.data ?? const <Product>[])
                  .where((p) => wishlist.contains(p.id))
                  .toList();
              final columns = AppDimens.gridColumns(
                MediaQuery.sizeOf(context).width,
              );
              return GridView.builder(
                padding: const EdgeInsets.all(AppDimens.lg),
                gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: columns,
                  mainAxisSpacing: AppDimens.sm,
                  crossAxisSpacing: AppDimens.sm,
                  mainAxisExtent: AppDimens.gridProductHeight(context),
                ),
                itemCount: saved.length,
                itemBuilder: (context, i) => ProductCard(product: saved[i]),
              );
            },
          );
        },
      ),
    );
  }
}
