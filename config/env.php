<?php

function env(string $key, $default = null)
{
    if (array_key_exists($key, $_ENV)) {
        $value = $_ENV[$key];
        if ($value !== '') return $value;
    }

    if (array_key_exists($key, $_SERVER)) {
        $value = $_SERVER[$key];
        if ($value !== '') return $value;
    }

    $value = getenv($key);
    if ($value !== false && $value !== '') return $value;

    return $default;
}
