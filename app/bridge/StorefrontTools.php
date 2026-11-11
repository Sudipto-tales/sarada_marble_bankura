<?php

class StorefrontTools extends StorefrontController
{
    public function wishlist(): void
    {
        $this->handle(function (): void {
            $actor = CommerceAccess::actor();
            $products = (new ProductService($this->pdo))->selection((new WishlistService($this->pdo))->ids($actor['id'] ?? null));
            $this->respond('app/page/storefront/wishlist.php', compact('products', 'actor'));
        });
    }
    public function saveWishlist(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $actor = CommerceAccess::actor();
            $action = $_POST['action'] ?? 'add';
            if (!in_array($action, ['add','remove'], true)) CommerceValues::invalid('action');
            (new WishlistService($this->pdo))->change($actor['id'] ?? null, CommerceValues::id($_POST['product_id'] ?? null), $action === 'remove');
            $this->redirect('wishlist');
        });
    }
    public function comparison(): void
    {
        $this->handle(function (): void {
            RequestSecurity::session();
            $products = (new ProductService($this->pdo))->selection($_SESSION['compare_products'] ?? []);
            $this->respond('app/page/storefront/compare.php', compact('products'));
        });
    }
    public function compareChange(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $id = CommerceValues::id($_POST['product_id'] ?? null);
            $action = $_POST['action'] ?? 'add';
            if (!in_array($action, ['add','remove'], true)) CommerceValues::invalid('action');
            $ids = array_column((new ProductService($this->pdo))->selection($_SESSION['compare_products'] ?? []), 'id');
            $ids = array_map('intval', $ids);
            if ($action === 'remove') $ids = array_values(array_diff($ids, [$id]));
            elseif (!in_array($id, $ids, true)) {
                if (!(new ProductService($this->pdo))->selection([$id])) throw new RequestRejected(404, 'not_found', 'Material not found.');
                if (count($ids) >= 4) throw new RequestRejected(422, 'compare_limit', 'Compare up to four materials. Remove one before adding another.');
                $ids[] = $id;
            }
            $_SESSION['compare_products'] = $ids;
            $this->redirect('compare');
        });
    }
    public function calculator(): void
    {
        $this->handle(function (): void {
            $products = (new CatalogReadService($this->pdo))->listing(['per_page'=>24])['items'];
            $variant = null;
            if (isset($_GET['variant_id']) && $_GET['variant_id'] !== '') {
                $id = CommerceValues::id($_GET['variant_id']);
                $productId = CommerceDatabase::query($this->pdo, 'SELECT product_id FROM product_variants WHERE id=? AND enabled=1', [$id])->fetchColumn();
                $selected = $productId ? (new ProductService($this->pdo))->selection([(int)$productId]) : [];
                foreach ($selected[0]['variants'] ?? [] as $candidate) if ((int)$candidate['id'] === $id) $variant = $candidate;
                if ($variant === null) throw new RequestRejected(404, 'not_found', 'Variant not found.');
                if (!in_array((int)$productId, array_map('intval', array_column($products, 'id')), true)) $products[] = $selected[0];
            }
            $input = $_GET + ['surfaces'=>[['name'=>'Floor','length'=>'14','width'=>'12','unit'=>'ft','cutout'=>'0']],
                'waste_bps'=>'1000','polish_rate'=>'0','install_rate'=>'0'];
            $estimate = AreaCalculator::estimate($input, $variant);
            $cart = (new CartService($this->pdo))->get(CartService::principal(true));
            $this->respond('app/page/storefront/calculator.php', compact('products','variant','input','estimate','cart'));
        });
    }
    public function help(): void
    {
        $this->handle(fn() => $this->respond('app/page/storefront/visualizer_help.php'));
    }
    public function visualizer(): void { $this->photoPage(false); }
    public function designs(): void { $this->photoPage(true); }
    private function photoPage(bool $saved): void
    {
        $this->handle(function () use ($saved): void {
            $actor = CommerceAccess::actor();
            $selectedId = isset($_GET['product_id']) ? CommerceValues::id($_GET['product_id']) : null;
            $catalogMaterials = (new CatalogReadService($this->pdo))->visualizerMaterials($selectedId);
            if ($selectedId !== null && !in_array($selectedId, array_map('intval', array_column($catalogMaterials, 'id')), true))
                throw new RequestRejected(404, 'not_found', 'Published material with a photograph not found.');
            $materials = array_map(static fn(array $material): array => [
                'id'=>'store_'.$material['id'], 'name'=>$material['name'], 'source'=>'catalog',
                'textureUrl'=>self::url($material['image_path']), 'thumbnailUrl'=>self::url($material['image_path']),
                'detailUrl'=>self::url('product/'.$material['slug'])], $catalogMaterials);
            $samples = json_decode(file_get_contents(__DIR__.'/../../assets/storefront/visualizer-materials.json'), true, 16, JSON_THROW_ON_ERROR);
            foreach ($samples as &$sample) { $sample['name'] = 'Sample · '.$sample['name']; $sample['source'] = 'sample'; }
            unset($sample);
            $config = ['assets'=>self::url('assets/storefront/'), 'visualizer'=>self::url('visualizer'),
                'saved'=>self::url('saved-designs'), 'shop'=>self::url('shop'), 'mode'=>$saved ? 'saved' : 'editor',
                'storageScope'=>'sarada-php-photos-' . hash('sha256', self::url() . ':' . ($actor ? 'user:'.$actor['id'] : 'guest')),
                'materials'=>array_merge($materials, $samples), 'selectedMaterial'=>$selectedId === null ? null : 'store_'.$selectedId,
                'rooms'=>[['luxury_living','Luxury Living'],['sunlit_living','Sunlit Living'],['luxury_bedroom','Luxury Bedroom'],
                    ['premium_bathroom','Premium Bathroom'],['modern_kitchen','Modern Kitchen'],['hotel_lobby','Hotel Lobby'],['villa_interior','Villa Interior'],['modern_office','Modern Office']]];
            $this->respond('app/page/storefront/visualizer.php', compact('config','saved'));
        });
    }
}
