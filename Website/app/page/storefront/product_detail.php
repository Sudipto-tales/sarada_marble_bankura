<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($product['name'] ?? 'Product') ?> - Maa Sarada Marble</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/minimal-icons.min.css">
    <style>
        .detail-img { max-width: 100%; height: auto; border-radius: 4px; margin: 1rem 0; }
        .detail-specs { background: #f8f9fa; padding: 1rem; border-radius: 4px; margin: 1rem 0; }
        .price-large { font-size: 1.5rem; font-weight: bold; color: #28a745; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-md navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="/">Maa Sarada Marble</a>
    </div>
</nav>

<div class="container">
    <div class="row">
        <div class="col-md-6">
            <img src="/uploads/products/<?= htmlspecialchars($product['primary_image'] ?? 'default.jpg') ?>" class="detail-img" alt="<?= htmlspecialchars($product['name']) ?>">
        </div>
        <div class="col-md-6">
            <h1><?= htmlspecialchars($product['name']) ?></h1>
            <p class="price-large">INR <?= number_format($product['min_price_minor'] / 100, 2) ?></p>
            <p class="text-muted">Category: <?= htmlspecialchars($product['category_name'] ?? '') ?></p>
            <p class="text-muted">Brand: <?= htmlspecialchars($product['brand_name'] ?? '') ?></p>
            
            <div class="detail-specs">
                <h6>Specifications</h6>
                <ul>
                    <li>SKU: <?= htmlspecialchars($product['sku'] ?? '-') ?></li>
                    <li>Sell Unit: <?= htmlspecialchars($product['sell_unit'] ?? '-') ?></li>
                    <?php if($product['coverage_sqft_milli']): ?>
                        <li>Coverage: <?= number_format($product['coverage_sqft_milli'] / 1000, 2) ?> sqft</li>
                    <?php endif; ?>
                    <?php if($product['qty_increment_milli']): ?>
                        <li>Increment: <?= number_format($product['qty_increment_milli'] / 1000, 0) ?> <?= htmlspecialchars($product['sell_unit'] ?? 'unit') ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <p class="text-muted"><?= htmlspecialchars($product['description'] ?? 'No description available') ?></p>
            
            <a href="/category/<?= htmlspecialchars($product['category_slug'] ?? '') ?>" class="btn btn-primary">Back to Catalog</a>
        </div>
    </div>
</div>

<footer class="mt-4 text-center text-muted">
    <small>Maa Sarada Marble Solutions</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>