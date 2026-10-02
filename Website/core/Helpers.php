<?php

if (!function_exists('view_data')) {
    function view_data(array $data = [], array $extras = []): array
    {
        return array_merge($data, $extras);
    }
}

if (!function_exists('render_view')) {
    function render_view(string $path, array $data = [])
    {
        return load_view($path, $data);
    }
}

if (!function_exists('api_request')) {
    function api_request(string $url, string $method = 'GET', array $options = [])
    {
        $method = strtoupper($method);
        $query = $options['query'] ?? [];
        $headers = $options['headers'] ?? [];
        $payload = $options['payload'] ?? null;

        if ($query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $headers = array_merge(['Accept: application/json'], $headers);
        $curlAvailable = function_exists('curl_init');

        if ($curlAvailable) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if ($payload !== null && $method !== 'GET') {
                if (is_array($payload)) {
                    $payload = json_encode($payload);
                    $headers[] = 'Content-Type: application/json';
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                return ['success' => false, 'error' => $error ?: 'cURL request failed'];
            }
        } else {
            $contextOptions = [
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                ],
            ];

            if ($payload !== null && $method !== 'GET') {
                $contextOptions['http']['content'] = is_array($payload) ? json_encode($payload) : $payload;
            }

            $context = stream_context_create($contextOptions);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                return ['success' => false, 'error' => 'Unable to fetch API response'];
            }
        }

        $decoded = json_decode($response, true);
        return $decoded !== null ? $decoded : $response;
    }
}

if (!function_exists('api_get')) {
    function api_get(string $url, array $query = [], array $headers = [])
    {
        return api_request($url, 'GET', ['query' => $query, 'headers' => $headers]);
    }
}

if (!function_exists('api_post')) {
    function api_post(string $url, array $payload = [], array $headers = [])
    {
        return api_request($url, 'POST', ['payload' => $payload, 'headers' => $headers]);
    }
}

?>