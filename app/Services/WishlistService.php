<?php

final class WishlistService
{
    public function __construct(private PDO $pdo) {}
    public function ids(?int $userId): array
    {
        RequestSecurity::session();
        if ($userId === null) return array_values($_SESSION['guest_wishlist'] ?? []);
        CommerceAccess::requireActor($userId);
        return array_map('intval', CommerceDatabase::query($this->pdo,
            'SELECT product_id FROM wishlists WHERE user_id=? ORDER BY product_id LIMIT 100', [$userId])->fetchAll(PDO::FETCH_COLUMN));
    }
    public function change(?int $userId, int $productId, bool $remove): void
    {
        RequestSecurity::session();
        if (!$remove && !(new ProductService($this->pdo))->selection([$productId]))
            throw new RequestRejected(404, 'not_found', 'Material not found.');
        if ($userId === null) {
            $ids = $this->ids(null);
            if ($remove) $ids = array_values(array_diff($ids, [$productId]));
            elseif (!in_array($productId, $ids, true)) {
                if (count($ids) >= 100) throw new RequestRejected(422, 'wishlist_limit', 'Save up to 100 materials.');
                $ids[] = $productId;
            }
            $_SESSION['guest_wishlist'] = $ids;
            return;
        }
        CommerceDatabase::transaction($this->pdo, function () use ($userId, $productId, $remove): void {
            CommerceDatabase::query($this->pdo, 'SELECT id FROM users_tbl WHERE id=?' . CommerceDatabase::lock($this->pdo), [$userId])->fetch();
            CommerceAccess::requireActor($userId);
            $ids = $this->ids($userId);
            if ($remove) CommerceDatabase::query($this->pdo, 'DELETE FROM wishlists WHERE user_id=? AND product_id=?', [$userId,$productId]);
            elseif (!in_array($productId, $ids, true)) {
                if (count($ids) >= 100) throw new RequestRejected(422, 'wishlist_limit', 'Save up to 100 materials.');
                CommerceDatabase::query($this->pdo, 'INSERT INTO wishlists (user_id,product_id,created_at) VALUES (?,?,?)', [$userId,$productId,time()]);
            }
        });
    }
}
