<?php
// SSRF screening for image-by-URL import (IMG-URL-01). IP literals keep this check offline/deterministic.
require __DIR__ . '/support/CommerceTest.php';
CommerceTest::open(); $check = [CommerceTest::class, 'check'];

// Rejected: non-http(s) schemes, embedded credentials, and every non-public address class.
foreach ([
    'ftp://1.1.1.1/surface.jpg' => 'FTP scheme rejected.',
    'file:///etc/passwd' => 'file scheme rejected.',
    'http://user:pass@1.1.1.1/x.jpg' => 'URL with embedded credentials rejected.',
    'http://127.0.0.1/x.jpg' => 'IPv4 loopback rejected.',
    'http://10.0.0.5/x.jpg' => 'Private 10/8 address rejected.',
    'http://192.168.1.10/x.jpg' => 'Private 192.168/16 address rejected.',
    'http://172.16.4.4/x.jpg' => 'Private 172.16/12 address rejected.',
    'http://169.254.1.1/x.jpg' => 'Link-local address rejected.',
    'http://[::1]/x.jpg' => 'IPv6 loopback rejected.',
    'not-a-url' => 'Non-URL input rejected.',
    '' => 'Empty URL rejected.',
] as $url => $message) {
    CommerceTest::rejects(fn() => ProductImageService::validateUrl($url), 422, $message);
}

// Accepted: a public literal address resolves to a pinnable, screened target.
$screened = ProductImageService::validateUrl('http://1.1.1.1/surface.jpg');
$check($screened['host'] === '1.1.1.1' && $screened['ip'] === '1.1.1.1' && $screened['port'] === 80, 'Public http URL is screened and pinned.');
$https = ProductImageService::validateUrl('https://1.0.0.1/a.png');
$check($https['port'] === 443, 'https default port is applied.');

CommerceTest::finish('Image URL SSRF screening');
