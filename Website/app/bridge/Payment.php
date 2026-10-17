<?php

class Payment extends StorefrontController
{
    public function verify(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation(); $actor = CommerceAccess::requireActor();
            $result = (new PaymentService($this->pdo))->confirmCallback($actor['id'],
                CommerceValues::text($_POST['razorpay_order_id'] ?? '', 64, 'order_id'),
                CommerceValues::text($_POST['razorpay_payment_id'] ?? '', 64, 'payment_id'),
                CommerceValues::text($_POST['razorpay_signature'] ?? '', 64, 'signature'));
            $_SESSION['order_just_placed'] = $result['id'];
            $this->redirect('order/' . $result['id']);
        });
    }

    public function webhook(): void
    {
        $this->handle(function (): void {
            $rawBody = file_get_contents('php://input');
            if ($rawBody === false) throw new RequestRejected(400, 'invalid_webhook', 'Invalid webhook.');
            (new PaymentService($this->pdo))->handleWebhook($rawBody, (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? ''));
            echo '{"status":"ok"}';
        }, true);
    }
}
