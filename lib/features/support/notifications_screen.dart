import 'package:flutter/material.dart';

import '../../core/routing/routes.dart';
import '../../core/state/app_scope.dart';
import '../../core/theme/app_colors.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/state_views.dart';
import '../../data/models/app_notification.dart';

class NotificationsScreen extends StatelessWidget {
  const NotificationsScreen({super.key});

  static IconData _icon(NotificationKind kind) => switch (kind) {
        NotificationKind.order => Icons.receipt_long_rounded,
        NotificationKind.offer => Icons.local_offer_rounded,
        NotificationKind.delivery => Icons.local_shipping_rounded,
        NotificationKind.design => Icons.view_in_ar_rounded,
        NotificationKind.general => Icons.notifications_rounded,
      };

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          Observer(
            listenable: deps.notificationCenter,
            builder: (context, center) => center.unread == 0
                ? const SizedBox.shrink()
                : TextButton(
                    onPressed: center.markAllRead,
                    child: const Text('Mark all read'),
                  ),
          ),
        ],
      ),
      body: Observer(
        listenable: deps.notificationCenter,
        builder: (context, center) {
          if (center.isLoading) return const LoadingView();
          if (center.items.isEmpty) {
            return const EmptyView(
              icon: Icons.notifications_none_rounded,
              title: 'No notifications',
              message: 'Order updates and offers will show up here.',
            );
          }
          return ListView.separated(
            itemCount: center.items.length,
            separatorBuilder: (_, _) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final n = center.items[i];
              return ListTile(
                tileColor: n.read
                    ? null
                    : AppColors.ice.withValues(alpha: 0.35),
                leading: CircleAvatar(
                  backgroundColor: AppColors.ice,
                  child: Icon(_icon(n.kind), size: 19, color: AppColors.deep),
                ),
                title: Text(n.title,
                    style: Theme.of(context).textTheme.titleSmall),
                subtitle: Padding(
                  padding: const EdgeInsets.only(top: 3),
                  child: Text(n.body,
                      style: Theme.of(context)
                          .textTheme
                          .bodySmall
                          ?.copyWith(height: 1.35)),
                ),
                trailing: Text(Fmt.relative(n.at),
                    style: Theme.of(context).textTheme.labelSmall),
                onTap: () {
                  center.markRead(n.id);
                  if (n.orderId != null) {
                    Navigator.pushNamed(context, Routes.orderDetails,
                        arguments: OrderArgs(n.orderId!));
                  } else if (n.productId != null) {
                    Navigator.pushNamed(context, Routes.productDetails,
                        arguments: ProductArgs(n.productId!));
                  }
                },
              );
            },
          );
        },
      ),
    );
  }
}
