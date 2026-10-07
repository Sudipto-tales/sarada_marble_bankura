<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Maa Sarada Marble - Shopping Cart</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/minimal-icons.min.css">
    <style>
        .cart-item { border-bottom: 1px solid #dee2e6; padding: 1rem 0; }
        .cart-item:last-child { border-bottom: none; }
        .price { font-weight: bold; color: #28a745; }
        .quantity-input { width: 60px; }
        .subtotal { font-weight: bold; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-md navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="/">Maa Sarada Marble</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="/cart">Cart</a></li>
                <li class="nav-item"><a class="nav-link" href="/account">Account</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
    <?php if($cart??['id']===0): ?>
        <div class="alert alert-info">
            Your cart is empty. <a href="/">Continue shopping</a>.
        </div>
    <?php else: ?>
        <h3>Shopping Cart</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Price</th>
                    <th>Subtotal</th>
                    <th>Actions</th>
                </th>
            </thead>
            <tbody>
                <?php 
                $total_minor = 0;
                foreach($cart['items'] as $item): 
                    $line_total = $item['unit_price_minor'] * $item['qty_milli'] / 1000;
                    $total_minor += $line_total;
                ?>
                <tr class="cart-item">
                    <td>
                        <strong><?= htmlspecialchars($item['name']) ?></strong><br>
                        <small>SKU: <?= htmlspecialchars($item['sku']) ?></small>
                    </td>
                    <td>
                        <input type="number" name="qty[<?= $item['variant_id'] ?>]" value="<?= $item['qty_milli'] ?>" class="quantity-input form-control form-control-sm" min="1" max="9999">
                    </td>
                    <td class="price">INR <?= number_format($item['unit_price_minor'] / 100, 2) ?></td>
                    <td class="subtotal">INR <?= number_format($line_total / 100, 2) ?></td>
                    <td>
                        <a href="/cart/change/<?= $item['variant_id'] ?>/remove" class="btn btn-link btn-sm">Remove</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="alert alert-success mt-3">
            <strong>Cart Total:</strong> INR <?= number_format($total_minor / 100, 2) ?>
        </div>
        
        <a href="/checkout" class="btn btn-primary w-100">Proceed to Checkout</a>
    <?php endif; ?>
</div>

<footer class="mt-4 text-center text-muted">
    <small>Maa Sarada Marble Solutions</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>