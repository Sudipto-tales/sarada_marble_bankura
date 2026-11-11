<?php

class StorefrontProductController extends StorefrontController
{
    public function listing(): void
    {
        $this->handle(function (): void {
            $result = (new CatalogReadService($this->pdo))->listing($_GET);
            $items = $result['items']; unset($result['items']);
            $data=DeveloperTrace::span('StorefrontController::jsonValues',fn()=>self::jsonValues($items));
            echo json_encode(['data' => $data, 'meta' => $result, 'request_id' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR);
        }, true);
    }
    public function detail(string $slug): void
    {
        $this->handle(function () use ($slug): void {
            $product=(new CatalogReadService($this->pdo))->detail($slug);
            $data=DeveloperTrace::span('StorefrontController::jsonValues',fn()=>self::jsonValues($product));
            echo json_encode(['data' => $data, 'request_id' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR);
        }, true);
    }
}
