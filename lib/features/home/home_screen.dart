import 'package:flutter/material.dart';

import '../../core/config/feature_flags.dart';
import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/widgets/brand_widgets.dart';
import '../../core/widgets/state_views.dart';
import '../../core/widgets/shimmer.dart';
import '../../data/models/category.dart';
import '../../data/models/coupon.dart';
import '../../data/models/product.dart';
import '../../data/models/order.dart';
import 'widgets/deals_showcase.dart';
import 'widgets/category_browser.dart';
import '../catalog/widgets/product_card.dart';
import 'widgets/home_header.dart';
import 'widgets/home_sections.dart';
import '../brand_catalogs/brand_catalog_widgets.dart';

/// Everything the home screen needs, fetched once so there is a single
/// loading / error / retry surface instead of fifteen.
class HomeFeed {
  const HomeFeed({
    required this.banners,
    required this.categories,
    required this.featured,
    required this.trending,
    required this.bestSellers,
    required this.offers,
    required this.inspiration,
    required this.recentlyViewed,
    required this.deals,
    required this.buyAgain,
    required this.pastPurchaseSimilar,
    required this.allProducts,
  });

  final List<PromoBanner> banners;
  final List<Category> categories;
  final List<Product> featured;
  final List<Product> trending;
  final List<Product> bestSellers;
  final List<Offer> offers;
  final List<PromoBanner> inspiration;
  final List<Product> recentlyViewed;
  final List<Product> deals;
  final List<Product> buyAgain;
  final List<Product> pastPurchaseSimilar;
  final List<Product> allProducts;
}

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen>
    with AutomaticKeepAliveClientMixin {
  late Future<HomeFeed> _future;

  @override
  bool get wantKeepAlive => true;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<HomeFeed> _load() async {
    final deps = AppScope.read(context);
    final results = await Future.wait([
      deps.promos.banners(),
      deps.categories.all(),
      deps.products.featured(),
      deps.products.trending(),
      deps.products.bestSellers(),
      deps.promos.offers(),
      deps.promos.inspiration(),
      deps.products.byIds(deps.browsing.recentlyViewed),
      deps.products.all(),
      deps.orders.all(),
    ]);
    final all = results[8] as List<Product>;
    final deals = all.where((p) => p.discount >= 25).toList()
      ..sort((a, b) => b.discount.compareTo(a.discount));
    final orders = results[9] as List<Order>;
    final purchasedIds = orders
        .where(
          (o) =>
              o.status != OrderStatus.cancelled &&
              o.status != OrderStatus.returned,
        )
        .expand((o) => o.items)
        .map((i) => i.productId)
        .toSet();
    final purchased = all.where((p) => purchasedIds.contains(p.id)).toList();
    final categories = purchased.map((p) => p.categoryId).toSet();
    return HomeFeed(
      allProducts: all,
      banners: results[0] as List<PromoBanner>,
      categories: results[1] as List<Category>,
      featured: results[2] as List<Product>,
      trending: results[3] as List<Product>,
      bestSellers: results[4] as List<Product>,
      offers: results[5] as List<Offer>,
      inspiration: results[6] as List<PromoBanner>,
      recentlyViewed: results[7] as List<Product>,
      deals: deals.take(8).toList(),
      buyAgain: purchased,
      pastPurchaseSimilar: all
          .where(
            (p) =>
                !purchasedIds.contains(p.id) &&
                categories.contains(p.categoryId),
          )
          .take(8)
          .toList(),
    );
  }

  Future<void> _refresh() async {
    setState(() => _future = _load());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);
    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<HomeFeed>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const CustomScrollView(
                slivers: [
                  HomeHeader(),
                  SliverToBoxAdapter(child: _HomeSkeleton()),
                ],
              );
            }
            if (snap.hasError) {
              return CustomScrollView(
                slivers: [
                  const HomeHeader(),
                  SliverFillRemaining(
                    hasScrollBody: false,
                    child: ErrorView(onRetry: _refresh),
                  ),
                ],
              );
            }
            return _HomeContent(feed: snap.data!);
          },
        ),
      ),
    );
  }
}

class _HomeContent extends StatelessWidget {
  const _HomeContent({required this.feed});

  final HomeFeed feed;

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return CustomScrollView(
      slivers: [
        const HomeHeader(),
        SliverList.list(
          children: [
            DealsShowcase(banners: feed.banners),
            SectionHeader(
              title: 'Shop by category',
              subtitle: 'Find the right stone for your project',
              actionLabel: 'All',
              onAction: () => Navigator.pushNamed(
                context,
                Routes.catalog,
                arguments: const CatalogArgs(),
              ),
            ),
            HomeCategoryBrowser(
              categories: feed.categories,
              products: feed.allProducts,
            ),
            const SectionGap(),
            const ModuleShortcuts(),
            const SectionGap(),
            SectionHeader(
              title: 'Featured collection',
              subtitle: 'Hand-picked slabs from this month',
              actionLabel: 'See all',
              onAction: () => Navigator.pushNamed(
                context,
                Routes.catalog,
                arguments: const CatalogArgs(title: 'Featured'),
              ),
            ),
            ProductRail(products: feed.featured),
            const SectionGap(),
            SectionHeader(
              title: 'Offers for you',
              subtitle: 'Coupons applied at checkout',
              actionLabel: 'Coupons',
              onAction: () => Navigator.pushNamed(context, Routes.coupons),
            ),
            OfferStrip(offers: feed.offers),
            const SectionGap(),
            if (feed.deals.isNotEmpty) ...[
              SectionHeader(
                title: 'Today’s deals',
                subtitle: 'Beautiful finds, better prices',
                actionLabel: 'All deals',
                onAction: () => Navigator.pushNamed(
                  context,
                  Routes.catalog,
                  arguments: const CatalogArgs(
                    title: 'Top deals',
                    onlyOffers: true,
                  ),
                ),
              ),
              ProductRail(products: feed.deals),
              const SectionGap(),
            ],
            if (FeatureFlags.visualizationEnabled) ...[
              const VisualizerPromo(),
              const SectionGap(),
            ],
            if (feed.buyAgain.isNotEmpty) ...[
              SectionHeader(
                title: 'Buy again',
                subtitle: 'Your past purchases, ready for the next project',
                actionLabel: 'Orders',
                onAction: () => Navigator.pushNamed(context, Routes.orders),
              ),
              ProductRail(products: feed.buyAgain),
              const SectionGap(),
            ],
            if (feed.pastPurchaseSimilar.isNotEmpty) ...[
              const SectionHeader(
                title: 'Inspired by your purchases',
                subtitle: 'More stones in the styles you chose',
              ),
              ProductRail(products: feed.pastPurchaseSimilar),
              const SectionGap(),
            ],
            const SectionHeader(
              title: 'Trending now',
              subtitle: 'What other buyers are viewing this week',
            ),
            ProductRail(products: feed.trending),
            const SectionGap(),
            const SectionHeader(
              title: 'Get the look',
              subtitle: 'Real rooms, shoppable stone',
            ),
            InspirationRail(items: feed.inspiration),
            const SectionGap(),
            const SectionHeader(
              title: 'Best sellers',
              subtitle: 'Consistently reordered by contractors',
            ),
            ProductRail(products: feed.bestSellers),
            const SectionGap(),
            const TrustStrip(),
            const SectionGap(),
            if (feed.recentlyViewed.isNotEmpty) ...[
              SectionHeader(
                title: 'Recently viewed',
                actionLabel: 'Clear',
                onAction: deps.browsing.clearRecent,
              ),
              RecentlyViewedRail(products: feed.recentlyViewed),
              const SectionGap(),
            ],
            const HomeBrandCatalogs(),
            const SizedBox(height: 16),
            const SupportCard(),
            const SizedBox(height: 110),
          ],
        ),
      ],
    );
  }
}

class _HomeSkeleton extends StatelessWidget {
  const _HomeSkeleton();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: AppDimens.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Shimmer(width: 120, height: 12),
                SizedBox(height: 14),
                Shimmer(width: 240, height: 64),
                SizedBox(height: 12),
                Shimmer(width: 200, height: 16),
              ],
            ),
          ),
          Padding(
            padding: AppDimens.screenPad,
            child: SizedBox(
              height: 236,
              child: const Shimmer(height: 236, radius: 32),
            ),
          ),
          const SizedBox(height: AppDimens.xl),
          SizedBox(
            height: 90,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: AppDimens.screenPad,
              itemCount: 5,
              separatorBuilder: (_, _) => const SizedBox(width: 12),
              itemBuilder: (_, _) =>
                  const Shimmer(width: 72, height: 72, radius: 24),
            ),
          ),
          const SizedBox(height: 24),
          SizedBox(
            height: AppDimens.productHeight(context, 168),
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: AppDimens.screenPad,
              itemCount: 4,
              separatorBuilder: (_, _) => const SizedBox(width: AppDimens.sm),
              itemBuilder: (context, _) =>
                  const ProductCardSkeleton(width: 168),
            ),
          ),
        ],
      ),
    );
  }
}
