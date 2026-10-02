import 'package:flutter/material.dart';

import '../../../core/routing/routes.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_dimens.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/brand_widgets.dart';
import '../../../data/models/product.dart';
import '../../../data/models/review.dart';

class ReviewSummary extends StatelessWidget {
  const ReviewSummary({
    super.key,
    required this.product,
    required this.reviews,
    required this.breakdown,
  });

  final Product product;
  final List<Review> reviews;
  final Map<int, int> breakdown;

  @override
  Widget build(BuildContext context) {
    final total = breakdown.values.fold(0, (a, b) => a + b);
    final t = Theme.of(context).textTheme;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(
          title: 'Ratings & reviews',
          actionLabel: reviews.isEmpty ? null : 'See all',
          onAction: reviews.isEmpty
              ? null
              : () => Navigator.pushNamed(context, Routes.reviews,
                  arguments: ReviewArgs(product.id)),
        ),
        Padding(
          padding: AppDimens.screenPad,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Column(
                children: [
                  Text(Fmt.rating(product.rating), style: t.displaySmall),
                  Row(
                    children: [
                      for (var i = 1; i <= 5; i++)
                        Icon(
                          i <= product.rating.round()
                              ? Icons.star_rounded
                              : Icons.star_border_rounded,
                          size: 15,
                          color: AppColors.gold,
                        ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text('${product.reviewCount} ratings', style: t.labelSmall),
                ],
              ),
              const SizedBox(width: AppDimens.xl),
              Expanded(
                child: Column(
                  children: [
                    for (var star = 5; star >= 1; star--)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 2),
                        child: Row(
                          children: [
                            SizedBox(
                              width: 14,
                              child: Text('$star', style: t.labelSmall),
                            ),
                            const Icon(Icons.star_rounded,
                                size: 11, color: AppColors.mutedSoft),
                            const SizedBox(width: 8),
                            Expanded(
                              child: ClipRRect(
                                borderRadius: BorderRadius.circular(3),
                                child: LinearProgressIndicator(
                                  value: total == 0
                                      ? 0
                                      : (breakdown[star] ?? 0) / total,
                                  minHeight: 6,
                                  backgroundColor: AppColors.line,
                                  color: star >= 4
                                      ? AppColors.success
                                      : star == 3
                                          ? AppColors.warning
                                          : AppColors.danger,
                                ),
                              ),
                            ),
                            const SizedBox(width: 8),
                            SizedBox(
                              width: 20,
                              child: Text('${breakdown[star] ?? 0}',
                                  style: t.labelSmall),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: AppDimens.lg),
        if (reviews.isEmpty)
          Padding(
            padding: AppDimens.screenPad,
            child: Text('No written reviews yet for this stone.',
                style: t.bodyMedium),
          )
        else
          for (final review in reviews.take(3)) ReviewTile(review: review),
        const SizedBox(height: AppDimens.sm),
        Padding(
          padding: AppDimens.screenPad,
          child: OutlinedButton.icon(
            onPressed: () => Navigator.pushNamed(context, Routes.writeReview,
                arguments: ReviewArgs(product.id)),
            icon: const Icon(Icons.rate_review_outlined, size: 18),
            label: const Text('Write a review'),
          ),
        ),
      ],
    );
  }
}

class ReviewTile extends StatelessWidget {
  const ReviewTile({super.key, required this.review});

  final Review review;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Container(
      margin: const EdgeInsets.fromLTRB(
          AppDimens.lg, 0, AppDimens.lg, AppDimens.md),
      padding: const EdgeInsets.all(AppDimens.md),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(AppDimens.radiusMd),
        border: Border.all(color: AppColors.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              RatingBadge(rating: review.rating, dense: true),
              const SizedBox(width: AppDimens.sm),
              Expanded(
                child: Text(review.title,
                    style: t.titleSmall,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(review.body, style: t.bodyMedium?.copyWith(height: 1.45)),
          const SizedBox(height: 10),
          Row(
            children: [
              CircleAvatar(
                radius: 11,
                backgroundColor: AppColors.ice,
                child: Text(
                  Fmt.initials(review.author),
                  style: const TextStyle(
                    fontSize: 9,
                    fontWeight: FontWeight.w700,
                    color: AppColors.deep,
                  ),
                ),
              ),
              const SizedBox(width: 7),
              Flexible(
                child: Text(
                  '${review.author}${review.location == null ? '' : ' · ${review.location}'}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: t.labelSmall,
                ),
              ),
              if (review.verified) ...[
                const SizedBox(width: 6),
                const Icon(Icons.verified_rounded,
                    size: 13, color: AppColors.success),
              ],
              const Spacer(),
              Text(Fmt.relative(review.date), style: t.labelSmall),
            ],
          ),
        ],
      ),
    );
  }
}
