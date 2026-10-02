import 'package:flutter/material.dart';

import 'state_views.dart';

/// One place where loading / error / empty / data are decided, so every screen
/// in the app behaves identically. Screens pass a loader and a builder.
class AsyncView<T> extends StatefulWidget {
  const AsyncView({
    super.key,
    required this.load,
    required this.builder,
    this.loading,
    this.isEmpty,
    this.empty,
    this.errorTitle,
    this.errorMessage,
  });

  final Future<T> Function() load;
  final Widget Function(BuildContext context, T data) builder;
  final Widget? loading;
  final bool Function(T data)? isEmpty;
  final Widget? empty;
  final String? errorTitle;
  final String? errorMessage;

  @override
  State<AsyncView<T>> createState() => AsyncViewState<T>();
}

class AsyncViewState<T> extends State<AsyncView<T>> {
  late Future<T> _future = widget.load();

  void reload() => setState(() => _future = widget.load());

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<T>(
      future: _future,
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return widget.loading ?? const LoadingView();
        }
        if (snap.hasError) {
          return ErrorView(
            onRetry: reload,
            title: widget.errorTitle ?? 'Something went wrong',
            message: widget.errorMessage ??
                'We could not load this right now. Please try again.',
          );
        }
        final data = snap.data as T;
        if (widget.isEmpty?.call(data) ?? false) {
          return widget.empty ??
              const EmptyView(
                icon: Icons.inbox_outlined,
                title: 'Nothing here yet',
                message: 'This list is empty for now.',
              );
        }
        return widget.builder(context, data);
      },
    );
  }
}
