<?php

/**
 * Soft-deletes a customer: disables sign-in and scrubs profile PII while keeping the order ledger.
 * Orders snapshot name/address into their own rows (0008_order_snapshots), so they are never touched.
 */
final class AccountDeletionService
{
    public function __construct(private PDO $pdo) {}

    public function delete(int $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            // status 0 blocks Auth sign-in/reset (Auth requires status === 1); the freed email lets the
            // person re-register later; the password is rotated to an unknown value.
            $this->pdo->prepare("UPDATE users_tbl SET status = 0, name = 'Deleted user',
                firstname = 'Deleted', lastname = 'User', email = ?, password = ?, remember_token = NULL,
                verify_token = NULL WHERE id = ?")
                ->execute(['deleted+' . $userId . '@deleted.invalid',
                    password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $userId]);
            // Address PII is only needed until an order snapshots it; carts/wishlists are personal state.
            $this->pdo->prepare('DELETE FROM addresses WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM wishlists WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare("UPDATE carts SET status = 'abandoned' WHERE user_id = ? AND status = 'active'")->execute([$userId]);
            $this->pdo->prepare('DELETE FROM auth_trusted_devices WHERE user_id = ?')->execute([$userId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
