<?php

if (!function_exists('download_image')) {
    function download_image(string $url, string $savePath): bool
    {
        $directory = dirname($savePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $content = @file_get_contents($url);
        if ($content === false) {
            return false;
        }
        if (file_put_contents($savePath, $content) === false) {
            return false;
        }
        return true;
    }
}
?>