/// Minimal loading / data / error wrapper so every screen can render the same
/// four states (loading, empty, error+retry, data) without extra packages.
class AsyncValue<T> {
  const AsyncValue._(this.data, this.error, this.isLoading);

  const AsyncValue.loading() : this._(null, null, true);
  const AsyncValue.data(T value) : this._(value, null, false);
  const AsyncValue.error(Object err) : this._(null, err, false);

  final T? data;
  final Object? error;
  final bool isLoading;

  bool get hasError => error != null;
  bool get hasData => data != null;

  R when<R>({
    required R Function() loading,
    required R Function(Object error) error,
    required R Function(T data) data,
  }) {
    if (isLoading) return loading();
    if (this.error != null) return error(this.error!);
    return data(this.data as T);
  }

  AsyncValue<T> copyWithLoading() => AsyncValue<T>._(data, null, true);
}
