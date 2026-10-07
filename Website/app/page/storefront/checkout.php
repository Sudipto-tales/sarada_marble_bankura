<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Maa Sarada Marble - Checkout</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/minimal-icons.min.css">
    <style>
        .form-label { font-weight: bold; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-md navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="/">Maa Sarada Marble</a>
        <a class="navbar-link" href="/cart">Back to Cart</a>
    </div>
</div>

<div class="container">
    <h2>Checkout</h2>
    
    <?php if($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" action="/checkout">
        <div class="mb-3">
            <label class="form-label">Delivery Address</label>
            <select name="address_id" class="form-select">
                <option value="">Select address</option>
                <?php foreach($addresses as $addr): ?>
                    <option value="<?= $addr['id'] ?>">
                        <?= htmlspecialchars($addr['recipient'] . ' ' . $addr['line1'] . ', ' . $addr['city']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Payment Mode</label>
            <select name="payment_mode" class="form-select">
                <option value="cod">Cash on Delivery (COD - unpaid)</option>
                <option value="simulation">Simulation (development only)</option>
            </select>
        </div>
        
        <input type="hidden" name="cart_id" value="<?= $cart['id'] ?>">
        <input type="hidden" name="cart_revision" value="<?= $cart['revision'] ?>">
        
        <button type="submit" class="btn btn-primary w-100">Place Order</button>
    </form>
</div>

<footer class="mt-4 text-center text-muted">
    <small>Maa Sarada Marble Solutions</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>