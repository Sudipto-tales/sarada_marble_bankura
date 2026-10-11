<?php

class StorefrontProductController extends StorefrontController
{
    public function listing(): void
    {
        $this->handle(function (): void {
            $result = (new CatalogReadService($this->pdo))->listing($_GET);
            $items = $result['items']; unset($result['items']);
            echo json_encode(['data' => self::jsonValues($items), 'meta' => $result, 'request_id' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR);
        }, true);
    }
    public function detail(string $slug): void
    {
        $this->handle(function () use ($slug): void {
            echo json_encode(['data' => self::jsonValues((new CatalogReadService($this->pdo))->detail($slug)), 'request_id' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR);
        }, true);
    }
}
