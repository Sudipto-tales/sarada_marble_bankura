import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_dimens.dart';
import '../../core/widgets/feedback.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/review.dart';
import 'widgets/review_summary.dart';

class ReviewsScreen extends StatefulWidget {
  const ReviewsScreen({super.key, required this.args});

  final ReviewArgs args;

  @override
  State<ReviewsScreen> createState() => _ReviewsScreenState();
}

class _ReviewsScreenState extends State<ReviewsScreen> {
  late Future<List<Review>> _future = _load();
  String _sort = 'Recent';

  Future<List<Review>> _load() =>
      AppScope.read(context).reviews.forProduct(widget.args.productId);

  List<Review> _sorted(List<Review> list) {
    final sorted = [...list];
    switch (_sort) {
      case 'Highest':
        sorted.sort((a, b) => b.rating.compareTo(a.rating));
      case 'Lowest':
        sorted.sort((a, b) => a.rating.compareTo(b.rating));
      case 'Helpful':
        sorted.sort((a, b) => b.helpfulCount.compareTo(a.helpfulCount));
      default:
        sorted.sort((a, b) => b.date.compareTo(a.date));
    }
    return sorted;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Reviews')),
      body: Column(
        children: [
          SizedBox(
            height: 52,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(
                  horizontal: AppDimens.lg, vertical: AppDimens.sm),
              children: [
                for (final option in ['Recent', 'Highest', 'Lowest', 'Helpful'])
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: Text(option),
                      selected: _sort == option,
                      onSelected: (_) => setState(() => _sort = option),
                    ),
                  ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: FutureBuilder<List<Review>>(
              future: _future,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const LoadingView();
                }
                if (snap.hasError) {
                  return ErrorView(onRetry: () => setState(() => _future = _load()));
                }
                final reviews = _sorted(snap.data ?? const []);
                if (reviews.isEmpty) {
                  return EmptyView(
                    icon: Icons.rate_review_outlined,
                    title: 'No reviews yet',
                    message: 'Be the first to review this stone.',
                    actionLabel: 'Write a review',
                    onAction: () => Navigator.pushNamed(
                        context, Routes.writeReview,
                        arguments: widget.args),
                  );
                }
                return ListView.builder(
                  padding: const EdgeInsets.only(top: AppDimens.md),
                  itemCount: reviews.length,
                  itemBuilder: (context, i) => ReviewTile(review: reviews[i]),
                );
              },
            ),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          await Navigator.pushNamed(context, Routes.writeReview,
              arguments: widget.args);
          if (mounted) setState(() => _future = _load());
        },
        icon: const Icon(Icons.edit_outlined),
        label: const Text('Write'),
      ),
    );
  }
}

class WriteReviewScreen extends StatefulWidget {
  const WriteReviewScreen({super.key, required this.args});

  final ReviewArgs args;

  @override
  State<WriteReviewScreen> createState() => _WriteReviewScreenState();
}

class _WriteReviewScreenState extends State<WriteReviewScreen> {
  final _formKey = GlobalKey<FormState>();
  final _title = TextEditingController();
  final _body = TextEditingController();
  double _rating = 5;
  bool _saving = false;

  @override
  void dispose() {
    _title.dispose();
    _body.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final deps = AppScope.read(context);
    final user = deps.session.user;
    await deps.reviews.submit(Review(
      id: 'r_${DateTime.now().millisecondsSinceEpoch}',
      productId: widget.args.productId,
      author: user?.name ?? 'Guest buyer',
      rating: _rating,
      title: _title.text.trim(),
      body: _body.text.trim(),
      date: DateTime.now(),
      verified: user != null,
    ));
    if (!mounted) return;
    setState(() => _saving = false);
    Toast.success(context, 'Thanks — your review was posted');
    Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Write a review')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(AppDimens.lg),
          children: [
            Text('Your rating', style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: AppDimens.sm),
            Row(
              children: [
                for (var i = 1; i <= 5; i++)
                  IconButton(
                    onPressed: () => setState(() => _rating = i.toDouble()),
                    icon: Icon(
                      i <= _rating ? Icons.star_rounded : Icons.star_border_rounded,
                      size: 32,
                      color: const Color(0xFFC9A24B),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: AppDimens.lg),
            TextFormField(
              controller: _title,
              decoration: const InputDecoration(labelText: 'Headline'),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'Add a short headline' : null,
            ),
            const SizedBox(height: AppDimens.md),
            TextFormField(
              controller: _body,
              maxLines: 6,
              decoration: const InputDecoration(
                labelText: 'Your experience',
                alignLabelWithHint: true,
                hintText: 'How did the stone look and hold up on site?',
              ),
              validator: (v) => (v == null || v.trim().length < 10)
                  ? 'Tell us a little more (10+ characters)'
                  : null,
            ),
            const SizedBox(height: AppDimens.xl),
            FilledButton(
              onPressed: _saving ? null : _submit,
              child: Text(_saving ? 'Posting…' : 'Post review'),
            ),
          ],
        ),
      ),
    );
  }
}
