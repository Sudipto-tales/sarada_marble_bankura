<?php

abstract class BaseController
{
    protected function viewData(array $data = [], array $extras = []): array
    {
        return view_data($data, $extras);
    }

    protected function respond(string $view, array $data = [])
    {
        return render_view($view, $data);
    }

    protected function apiGet(string $url, array $query = [], array $headers = [])
    {
        return api_get($url, $query, $headers);
    }

    protected function apiPost(string $url, array $payload = [], array $headers = [])
    {
        return api_post($url, $payload, $headers);
    }

    protected function defaultAppName(): string
    {
        $appName = env('APP_NAME', null);
        if ($appName !== null && $appName !== '') {
            return $appName;
        }

        $constant = static::class . '::DEFAULT_APP_NAME';
        if (defined($constant)) {
            return constant($constant);
        }

        return 'vayu';
    }
}
?>