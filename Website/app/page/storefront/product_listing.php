<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Maa Sarada Marble - Product Catalog</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/minimal-icons.min.css">
    <style>
        .product-card { transition: transform 0.2s; border: 1px solid #dee2e6; border-radius: 4px; margin-bottom: 1rem; }
        .product-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .product-image { height: 200px; object-fit: cover; border-radius: 4px 4px 0 0; }
        .product-footer { padding: 0.75rem; border-top: 1px solid #dee2e6; }
        .product-price { font-weight: bold; color: #28a745; }
        .pagination { margin-top: 1rem; }
        .filter-bar { background: #f8f9fa; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-md navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="/">Maa Sarada Marble</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item"><a class="nav-link" href="/">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="/category/tiles">Tiles</a></li>
                <li class="nav-item"><a class="nav-link" href="/category/floors">Floors</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
    <div class="filter-bar">
        <h4>Filter Products</h4>
        <form method="GET" action="/">
            <div class="row">
                <div class="col-md-3">
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach($categories ?? [] as $cat): ?>
                            <option value="<?= htmlspecialchars($cat['slug']) ?>" <?= ($category ?? '') == $cat['slug'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="sort" class="form-select">
                        <option value="featured">Featured First</option>
                        <option value="price_low">Price Low to High</option>
                        <option value="price_high">Price High to Low</option>
                        <option value="newest">Newest First</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input name="q" type="text" class="form-control" placeholder="Search..." value="<?= htmlspecialchars($query ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                </div>
            </div>
        </form>
    </div>

    <div class="row">
        <?php if($products??count([])===0): ?>
            <div class="col-12">
                <div class="alert alert-info">No products found matching your criteria.</div>
            </div>
        <?php else: ?>
            <?php foreach($products['items'] as $product): ?>
                <div class="col-md-4 col-lg-3">
                    <div class="product-card">
                        <img src="/uploads/products/<?= htmlspecialchars($product['primary_image'] ?? 'default.jpg') ?>" class="product-image" alt="<?= htmlspecialchars($product['name']) ?>">
                        <div class="product-footer">
                            <h5 class="card-title"><?= htmlspecialchars(mb_substr($product['name'], 0, 50) ) ?></h5>
                            <p class="card-text small text-muted"><?= htmlspecialchars($product['category_name'] ?? '') ?> / <?= htmlspecialchars($product['brand_name'] ?? '') ?></p>
                            <p class="card-text"><strong class="product-price">INR <?= number_format($product['min_price_minor'] / 100, 2) ?></strong></p>
                            <a href="/product/<?= htmlspecialchars($product['slug']) ?>" class="btn btn-sm btn-outline-primary w-100">View Details</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<nav class="pagination" aria-label="Page navigation">
    <ul>
        <?php if($products['page'] > 1): ?>
            <li><a href="?page=<?= $products['page']-1 ?>&category=<?= htmlspecialchars($category ?? '') ?>&sort=<?= htmlspecialchars($sort ?? 'featured') ?>">Previous</a></li>
        <?php endif; ?>
        <?php if($products['page'] < $products['pages']): ?>
            <li><a href="?page=<?= $products['page']+1 ?>&category=<?= htmlspecialchars($category ?? '') ?>&sort=<?= htmlspecialchars($sort ?? 'featured') ?>">Next</a></li>
        <?php endif; ?>
    </ul>
</nav>
</div>

<footer class="mt-4 text-center text-muted">
    <small>Maa Sarada Marble Solutions</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>