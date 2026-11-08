<?php

/** Bounded transport only. Never run network requests with database locks held. */
final class RazorpayClient
{
    public function __construct(private string $keyId, private string $keySecret, private int $timeout = 15)
    {
        if ($keyId === '' || $keySecret === '' || $timeout < 1 || $timeout > 30)
            throw new RuntimeException('Payment configuration is unavailable.');
    }

    public function createOrder(int $amountMinor, string $receipt, array $notes): array
    {
        return DeveloperTrace::span(__METHOD__,fn()=> $this->request('POST', '/orders', ['amount'=>$amountMinor,'currency'=>'INR','receipt'=>$receipt,'notes'=>$notes]));
    }

    public function fetchPayment(string $paymentId): array
    {
        if (!preg_match('/\Apay_[A-Za-z0-9]+\z/', $paymentId)) CommerceValues::invalid('payment_id');
        return DeveloperTrace::span(__METHOD__,fn()=> $this->request('GET', '/payments/' . rawurlencode($paymentId)));
    }

    /** Refund a captured payment. The idempotency key is replayed so a retried job never double-refunds. */
    public function refundPayment(string $paymentId, int $amountMinor, string $idempotency): array
    {
        if (!preg_match('/\Apay_[A-Za-z0-9]+\z/', $paymentId)) CommerceValues::invalid('payment_id');
        if ($amountMinor < 1) CommerceValues::invalid('amount');
        return DeveloperTrace::span(__METHOD__,fn()=> $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/refund', ['amount'=>$amountMinor], $idempotency));
    }

    private function request(string $method, string $path, ?array $body = null, ?string $idempotency = null): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Payment transport is unavailable.');
        $curl = curl_init('https://api.razorpay.com/v1' . $path);
        try {
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method,
                CURLOPT_HTTPAUTH=>CURLAUTH_BASIC, CURLOPT_USERPWD=>$this->keyId . ':' . $this->keySecret,
                CURLOPT_CONNECTTIMEOUT=>min(5, $this->timeout), CURLOPT_TIMEOUT=>$this->timeout,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json','Accept: application/json'],
                    $idempotency !== null ? ['X-Razorpay-Idempotency: ' . $idempotency] : [])]);
            if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
            $response = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if (!is_string($response) || $status < 200 || $status >= 300) {
                // Log classifiers only: provider response/cURL messages may contain secrets or PII.
                error_log('Razorpay transport unavailable: HTTP ' . $status . ', curl ' . curl_errno($curl));
                throw new RuntimeException('Payment provider is temporarily unavailable.');
            }
            $data = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new RuntimeException('Payment provider is temporarily unavailable.');
            return $data;
        } catch (Throwable $error) {
            throw new RuntimeException('Payment provider is temporarily unavailable.');
        } finally { curl_close($curl); }
    }
}
