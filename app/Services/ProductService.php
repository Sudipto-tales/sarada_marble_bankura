<?php

final class ProductService
{
    public function __construct(private PDO $pdo) {}
    private function query(string $sql, array $values = []): PDOStatement { return CommerceDatabase::query($this->pdo,$sql,$values); }

    public static function advanceVersion(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Catalog version must advance inside its mutation transaction.');
        if (CommerceDatabase::query($pdo,"UPDATE catalog_versions SET version = version + 1 WHERE namespace = 'catalog'")->rowCount() !== 1) {
            throw new RuntimeException('Catalog version is unavailable.');
        }
    }

    private function variants(array $rows): array
    {
        $validated = []; $skus = [];
        if (count($rows)>20) CommerceValues::invalid('variants');
        foreach ($rows as $row) {
            if (!is_array($row)) CommerceValues::invalid('variants');
            $sku = strtoupper(CommerceValues::text($row['sku'] ?? null,80,'sku'));
            if (!preg_match('/\A[A-Z0-9][A-Z0-9_.-]{0,79}\z/',$sku) || isset($skus[$sku])) CommerceValues::invalid('sku');
            $skus[$sku] = true;
            $unit = $row['sell_unit'] ?? null;
            if (!in_array($unit,['sqft','slab','box'],true)) CommerceValues::invalid('sell_unit');
            $increment = CommerceValues::integer($row['qty_increment_milli'] ?? null,1,1000000,'qty_increment_milli');
            if ($unit !== 'sqft' && $increment % 1000 !== 0) CommerceValues::invalid('qty_increment_milli');
            $variant = ['id'=>isset($row['id']) ? CommerceValues::id($row['id']) : null,'sku'=>$sku,
                'finish'=>CommerceValues::text($row['finish'] ?? '',80,'finish',true),'sell_unit'=>$unit,
                'qty_increment_milli'=>$increment,'unit_price_minor'=>CommerceValues::integer($row['unit_price_minor'] ?? null,0,CommerceValues::MAX_MONEY,'unit_price_minor'),
                'enabled'=>CommerceValues::flag($row['enabled'] ?? 1,'enabled')];
            foreach (['coverage_sqft_milli','thickness_mm_milli','length_mm_milli','width_mm_milli'] as $field) {
                $variant[$field] = isset($row[$field]) && $row[$field] !== '' ? CommerceValues::integer($row[$field],1,1000000000,$field) : null;
            }
            $validated[] = $variant;
        }
        return $validated;
    }

    public function save(int $actorId, array $input, int $expectedRevision, string $operationKey): array
    {
        CommerceAccess::requirePermission('admin.products.edit',$actorId);
        $operationKey = CommerceValues::key($operationKey);
        $id = isset($input['id']) ? CommerceValues::id($input['id']) : null;
        $status = $input['status'] ?? 'draft';
        if (!in_array($status,['draft','active','archived'],true)) CommerceValues::invalid('status');
        $data = ['slug'=>CommerceValues::slug($input['slug'] ?? null),'name'=>CommerceValues::text($input['name'] ?? null,190,'name'),
            'description'=>CommerceValues::text($input['description'] ?? '',10000,'description',true),
            'tags'=>CommerceValues::text($input['tags'] ?? '',1000,'tags',true),'category_id'=>CommerceValues::id($input['category_id'] ?? null,'category_id'),
            'brand_id'=>isset($input['brand_id']) && $input['brand_id'] !== '' ? CommerceValues::id($input['brand_id'],'brand_id') : null,
            'status'=>$status,'featured'=>CommerceValues::flag($input['featured'] ?? 0,'featured')];
        if (!is_array($input['variants'] ?? null)) CommerceValues::invalid('variants');
        $variants = $this->variants($input['variants']);
        if ($status === 'active' && !array_filter($variants,static fn(array $row):bool => (bool)$row['enabled'])) CommerceValues::invalid('variants');
        try {
            return CommerceDatabase::transaction($this->pdo,function() use($actorId,$id,$data,$variants,$expectedRevision,$operationKey):array {
                CommerceAccess::requirePermission('admin.products.edit',$actorId);
                $lock = CommerceDatabase::lock($this->pdo);
                $existing = $id ? $this->query('SELECT * FROM products WHERE id = ?' . $lock,[$id])->fetch() : null;
                if ($id && !$existing) throw new RequestRejected(404,'not_found','Product not found.');
                if ($expectedRevision !== ($existing ? (int)$existing['revision'] : 0)) throw new RequestRejected(409,'revision_conflict','Product changed. Reload before saving.');
                $current = $id ? $this->query('SELECT * FROM product_variants WHERE product_id = ? ORDER BY id LIMIT 101' . $lock,[$id])->fetchAll() : [];
                if (count($current)>100) CommerceValues::invalid('variants');
                $known = array_column($current,null,'id');
                $stockRows = [];
                if ($known) {
                    $variantIds = array_keys($known); $stockMarks = implode(',',array_fill(0,count($variantIds),'?'));
                    $stockRows = array_column($this->query("SELECT * FROM inventory WHERE variant_id IN ($stockMarks) ORDER BY variant_id".$lock,$variantIds)->fetchAll(),null,'variant_id');
                }
                $category = $this->query('SELECT * FROM categories WHERE id = ?',[$data['category_id']])->fetch();
                $brand = $data['brand_id'] ? $this->query('SELECT * FROM brands WHERE id = ?',[$data['brand_id']])->fetch() : null;
                if (!$category || ($data['brand_id'] && !$brand)) CommerceValues::invalid('category_or_brand');
                if ($data['status'] === 'active' && (!(int)$category['enabled'] || ($brand && !(int)$brand['enabled']))) CommerceValues::invalid('category_or_brand');
                $now = gmdate('Y-m-d\TH:i:s\Z');
                if ($id) {
                    $this->query('UPDATE products SET slug=?, name=?, description=?, tags=?, category_id=?, brand_id=?, status=?, featured=?, revision=revision+1, updated_at=? WHERE id=?',array_merge(array_values($data),[$now,$id]));
                } else {
                    $this->query('INSERT INTO products (slug,name,description,tags,category_id,brand_id,status,featured,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',array_merge(array_values($data),[$now,$now]));
                    $id = (int)$this->pdo->lastInsertId();
                }
                $kept = []; $seen = [];
                foreach ($variants as $variant) {
                    $variantId = $variant['id'];
                    if ($variantId && (!isset($known[$variantId]) || isset($seen[$variantId]))) CommerceValues::invalid('variant_id');
                    if ($variantId) $seen[$variantId] = true;
                    $values = [$variant['sku'],$variant['finish'],$variant['thickness_mm_milli'],$variant['length_mm_milli'],$variant['width_mm_milli'],
                        $variant['sell_unit'],$variant['qty_increment_milli'],$variant['coverage_sqft_milli'],$variant['unit_price_minor'],$variant['enabled']];
                    if ($variantId) {
                        $old = $known[$variantId];
                        $stock = $stockRows[$variantId] ?? null;
                        if ($old['sell_unit']!==$variant['sell_unit'] || (int)($old['coverage_sqft_milli']??0)!==(int)($variant['coverage_sqft_milli']??0)) {
                            $history=$this->query('SELECT id FROM inventory_movements WHERE variant_id=? ORDER BY id LIMIT 1'.$lock,[$variantId])->fetchColumn();
                            if ($history || ($stock && ((int)$stock['on_hand_milli']>0 || (int)$stock['reserved_milli']>0))) {
                                throw new RequestRejected(409,'unit_change_conflict','A variant with stock history cannot change its sell unit or coverage.');
                            }
                        }
                        $this->query('UPDATE product_variants SET sku=?,finish=?,thickness_mm_milli=?,length_mm_milli=?,width_mm_milli=?,sell_unit=?,qty_increment_milli=?,coverage_sqft_milli=?,unit_price_minor=?,enabled=?,revision=revision+1 WHERE id=?',array_merge($values,[$variantId]));
                    } else {
                        if (count($known)+count(array_filter($kept,static fn(int $item):bool => !isset($known[$item]))) >=100) CommerceValues::invalid('variants');
                        $this->query('INSERT INTO product_variants (sku,finish,thickness_mm_milli,length_mm_milli,width_mm_milli,sell_unit,qty_increment_milli,coverage_sqft_milli,unit_price_minor,enabled,product_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',array_merge($values,[$id]));
                        $variantId = (int)$this->pdo->lastInsertId();
                        InventoryService::initialize($this->pdo,$variantId);
                    }
                    $kept[] = $variantId;
                }
                foreach ($known as $variantId=>$old) if (!in_array((int)$variantId,$kept,true)) $this->query('UPDATE product_variants SET enabled=0, revision=revision+1 WHERE id=?',[$variantId]);
                self::advanceVersion($this->pdo);
                $revision = ($existing ? (int)$existing['revision'] : 0)+1;
                StaffAudit::record($this->pdo,$actorId,'product.save','product',$id,$operationKey,
                    ['revision'=>$revision,'status'=>$data['status'],'slug'=>$data['slug'],'variant_ids'=>$kept]);
                return ['id'=>$id,'revision'=>$revision,'variant_ids'=>$kept];
            });
        } catch (Throwable $e) {
            if (CommerceDatabase::uniqueConflict($e)) throw new RequestRejected(409,'catalog_conflict','A product slug or variant SKU already exists.');
            throw $e;
        }
    }

    public function archive(int $actorId, int $id, int $expectedRevision, string $key): array
    {
        CommerceAccess::requirePermission('admin.products.edit',$actorId); $key=CommerceValues::key($key);
        return CommerceDatabase::transaction($this->pdo,function() use($actorId,$id,$expectedRevision,$key):array {
            CommerceAccess::requirePermission('admin.products.edit',$actorId);
            $product=$this->query('SELECT * FROM products WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetch();
            if (!$product) throw new RequestRejected(404,'not_found','Product not found.');
            if ((int)$product['revision']!==$expectedRevision) throw new RequestRejected(409,'revision_conflict','Product changed.');
            $this->query("UPDATE products SET status='archived', revision=revision+1, updated_at=? WHERE id=?",[gmdate('Y-m-d\TH:i:s\Z'),$id]);
            self::advanceVersion($this->pdo);
            StaffAudit::record($this->pdo,$actorId,'product.archive','product',$id,$key,['status'=>'archived','revision'=>$expectedRevision+1]);
            return ['id'=>$id,'revision'=>$expectedRevision+1];
        });
    }

    public function saveCategory(int $actorId, array $input, string $key): int
    {
        CommerceAccess::requirePermission('admin.products.edit',$actorId); $key=CommerceValues::key($key);
        $id=isset($input['id'])?CommerceValues::id($input['id']):null;
        $slug=CommerceValues::slug($input['slug']??null); $name=CommerceValues::text($input['name']??null,190,'name');
        $enabled=CommerceValues::flag($input['enabled']??1,'enabled');
        $parent=isset($input['parent_id'])&&$input['parent_id']!==''?CommerceValues::id($input['parent_id']):null;
        try {
            return CommerceDatabase::transaction($this->pdo,function() use($actorId,$id,$slug,$name,$enabled,$parent,$key):int {
                CommerceAccess::requirePermission('admin.products.edit',$actorId);
                if (!$this->query('SELECT id FROM catalog_hierarchy_lock WHERE id=1'.CommerceDatabase::lock($this->pdo))->fetchColumn()) {
                    throw new RuntimeException('Category tree lock is unavailable.');
                }
                if ($id && !$this->query('SELECT id FROM categories WHERE id=?',[$id])->fetchColumn()) throw new RequestRejected(404,'not_found','Category not found.');
                $cursor=$parent; $visited=[];
                while ($cursor) {
                    if ($cursor===$id || isset($visited[$cursor]) || count($visited)>=30) CommerceValues::invalid('parent_id');
                    $visited[$cursor]=true;
                    $node=$this->query('SELECT id,parent_id FROM categories WHERE id=?',[$cursor])->fetch();
                    if (!$node) CommerceValues::invalid('parent_id');
                    $cursor=$node['parent_id']===null?null:(int)$node['parent_id'];
                }
                if ($id) $this->query('UPDATE categories SET slug=?,name=?,enabled=?,parent_id=? WHERE id=?',[$slug,$name,$enabled,$parent,$id]);
                else { $this->query('INSERT INTO categories (slug,name,enabled,parent_id) VALUES (?,?,?,?)',[$slug,$name,$enabled,$parent]); $id=(int)$this->pdo->lastInsertId(); }
                self::advanceVersion($this->pdo);
                StaffAudit::record($this->pdo,$actorId,'category.save','category',$id,$key,['name'=>$name,'slug'=>$slug,'enabled'=>$enabled]);
                return $id;
            });
        } catch (Throwable $e) { if (CommerceDatabase::uniqueConflict($e)) throw new RequestRejected(409,'catalog_conflict','Category slug already exists.'); throw $e; }
    }

    public function saveBrand(int $actorId, array $input, string $key): int
    {
        CommerceAccess::requirePermission('admin.products.edit',$actorId); $key=CommerceValues::key($key);
        $id=isset($input['id'])?CommerceValues::id($input['id']):null; $slug=CommerceValues::slug($input['slug']??null);
        $name=CommerceValues::text($input['name']??null,190,'name'); $enabled=CommerceValues::flag($input['enabled']??1,'enabled');
        try {
            return CommerceDatabase::transaction($this->pdo,function() use($actorId,$id,$slug,$name,$enabled,$key):int {
                CommerceAccess::requirePermission('admin.products.edit',$actorId);
                if ($id && !$this->query('SELECT id FROM brands WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetchColumn()) throw new RequestRejected(404,'not_found','Brand not found.');
                if ($id) $this->query('UPDATE brands SET slug=?,name=?,enabled=? WHERE id=?',[$slug,$name,$enabled,$id]);
                else { $this->query('INSERT INTO brands (slug,name,enabled) VALUES (?,?,?)',[$slug,$name,$enabled]); $id=(int)$this->pdo->lastInsertId(); }
                self::advanceVersion($this->pdo);
                StaffAudit::record($this->pdo,$actorId,'brand.save','brand',$id,$key,['name'=>$name,'slug'=>$slug,'enabled'=>$enabled]);
                return $id;
            });
        } catch (Throwable $e) { if (CommerceDatabase::uniqueConflict($e)) throw new RequestRejected(409,'catalog_conflict','Brand slug already exists.'); throw $e; }
    }

    public function addImage(int $actorId, int $productId, array $image, string $key): int
    {
        CommerceAccess::requirePermission('admin.products.edit',$actorId); $key=CommerceValues::key($key);
        $path=$image['path']??null; $mime=$image['mime']??null;
        $formats=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!is_string($path)||!isset($formats[$mime])||!preg_match('~\Aassets/products/[a-f0-9]{32}\.' . $formats[$mime] . '\z~',$path)) CommerceValues::invalid('image');
        $width=CommerceValues::integer($image['width']??null,1,6000,'width'); $height=CommerceValues::integer($image['height']??null,1,6000,'height');
        $alt=CommerceValues::text($image['alt_text']??'',190,'alt_text',true); $position=CommerceValues::integer($image['position']??0,0,1000,'position');
        $variant=isset($image['variant_id'])?CommerceValues::id($image['variant_id']):null;
        return CommerceDatabase::transaction($this->pdo,function() use($actorId,$productId,$variant,$path,$mime,$width,$height,$alt,$position,$key):int {
            CommerceAccess::requirePermission('admin.products.edit',$actorId);
            if (!$this->query('SELECT id FROM products WHERE id=?'.CommerceDatabase::lock($this->pdo),[$productId])->fetchColumn()) throw new RequestRejected(404,'not_found','Product not found.');
            if ($variant && !$this->query('SELECT id FROM product_variants WHERE id=? AND product_id=?',[$variant,$productId])->fetchColumn()) CommerceValues::invalid('variant_id');
            if ((int)$this->query('SELECT COUNT(*) FROM product_images WHERE product_id=?',[$productId])->fetchColumn()>=12) CommerceValues::invalid('images');
            $this->query('INSERT INTO product_images (product_id,variant_id,path,mime,width,height,position,alt_text) VALUES (?,?,?,?,?,?,?,?)',[$productId,$variant,$path,$mime,$width,$height,$position,$alt]);
            $id=(int)$this->pdo->lastInsertId(); self::advanceVersion($this->pdo);
            StaffAudit::record($this->pdo,$actorId,'image.save','product',$productId,$key,['image_id'=>$id]);
            return $id;
        });
    }

    public function taxonomies(?int $staffId = null): array
    {
        if ($staffId!==null) CommerceAccess::requirePermission('admin.products.view',$staffId);
        $where=$staffId===null?' WHERE enabled=1':'';
        return ['categories'=>$this->query('SELECT * FROM categories'.$where.' ORDER BY name,id LIMIT 1000')->fetchAll(),
            'brands'=>$this->query('SELECT * FROM brands'.$where.' ORDER BY name,id LIMIT 1000')->fetchAll()];
    }

    private function hydrate(array $products, bool $staff = false): array
    {
        if (!$products) return [];
        $ids=array_column($products,'id'); $marks=implode(',',array_fill(0,count($ids),'?'));
        $variants=$this->query("SELECT v.*,i.on_hand_milli,i.reserved_milli,(i.on_hand_milli-i.reserved_milli) AS available_milli
            FROM product_variants v LEFT JOIN inventory i ON i.variant_id=v.id WHERE v.product_id IN ($marks)".($staff?'':' AND v.enabled=1').' ORDER BY v.product_id,v.id',$ids)->fetchAll();
        $images=$this->query("SELECT * FROM product_images WHERE product_id IN ($marks) ORDER BY product_id,position,id",$ids)->fetchAll();
        $variantGroups=[]; $imageGroups=[];
        foreach ($variants as $variant) $variantGroups[$variant['product_id']][]=$variant;
        foreach ($images as $image) $imageGroups[$image['product_id']][]=$image;
        foreach ($products as &$product) {
            $product['variants']=$variantGroups[$product['id']]??[]; $product['images']=$imageGroups[$product['id']]??[];
            $prices=array_column(array_filter($product['variants'],static fn(array $row):bool=>(bool)$row['enabled']),'unit_price_minor');
            $product['min_price_minor']=$prices?min($prices):null;
            $product['in_stock']=(bool)array_filter($product['variants'],static fn(array $row):bool=>(bool)$row['enabled'] && $row['available_milli']!==null && (int)$row['available_milli']>=(int)$row['qty_increment_milli']);
        }
        unset($product); return $products;
    }

    public function listing(array $filters = []): array { return $this->listProducts($filters,null); }
    public function administration(int $actorId, array $filters = []): array
    {
        CommerceAccess::requirePermission('admin.products.view',$actorId); return $this->listProducts($filters,$actorId);
    }
    private function listProducts(array $filters, ?int $staffId): array
    {
        $page=CommerceValues::integer($filters['page']??1,1,1000,'page'); $perPage=CommerceValues::integer($filters['per_page']??12,1,50,'per_page');
        $clauses=[]; $values=[];
        if ($staffId===null) $clauses[]="p.status='active' AND c.enabled=1 AND (p.brand_id IS NULL OR b.enabled=1) AND EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id=p.id AND v.enabled=1)";
        foreach (['category'=>'c.slug','brand'=>'b.slug'] as $name=>$column) if (isset($filters[$name])&&$filters[$name]!=='') { $clauses[]="$column=?"; $values[]=CommerceValues::slug($filters[$name]); }
        if (isset($filters['q'])&&$filters['q']!=='') {
            $q=CommerceValues::text($filters['q'],80,'q'); $q='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';
            $clauses[]="(p.name LIKE ? ESCAPE '!' OR p.tags LIKE ? ESCAPE '!')"; $values[]=$q; $values[]=$q;
        }
        foreach (['min_price_minor'=>'>=','max_price_minor'=>'<='] as $name=>$compare) if (isset($filters[$name])&&$filters[$name]!=='') {
            $clauses[]="(SELECT MIN(pv.unit_price_minor) FROM product_variants pv WHERE pv.product_id=p.id AND pv.enabled=1) $compare ?";
            $values[]=CommerceValues::integer($filters[$name],0,CommerceValues::MAX_MONEY,$name);
        }
        $sorts=['featured'=>'p.featured DESC,p.id DESC','newest'=>'p.id DESC','price_low'=>'(SELECT MIN(unit_price_minor) FROM product_variants WHERE product_id=p.id AND enabled=1) ASC,p.id ASC',
            'price_high'=>'(SELECT MIN(unit_price_minor) FROM product_variants WHERE product_id=p.id AND enabled=1) DESC,p.id ASC'];
        $sort=$filters['sort']??'featured'; if (!is_string($sort)||!isset($sorts[$sort])) CommerceValues::invalid('sort');
        $from=' FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id';
        $where=$clauses?' WHERE '.implode(' AND ',$clauses):'';
        $total=(int)$this->query('SELECT COUNT(*)'.$from.$where,$values)->fetchColumn();
        $offset=($page-1)*$perPage;
        $rows=$this->query('SELECT p.*,c.name AS category_name,c.slug AS category_slug,b.name AS brand_name,b.slug AS brand_slug'.$from.$where.' ORDER BY '.$sorts[$sort]." LIMIT $perPage OFFSET $offset",$values)->fetchAll();
        return ['items'=>$this->hydrate($rows,$staffId!==null),'page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>(int)ceil($total/$perPage)];
    }
    public function detail(string $slug): array
    {
        $slug=CommerceValues::slug($slug);
        $row=$this->query("SELECT p.*,c.name AS category_name,c.slug AS category_slug,b.name AS brand_name,b.slug AS brand_slug
            FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id
            WHERE p.slug=? AND p.status='active' AND c.enabled=1 AND (p.brand_id IS NULL OR b.enabled=1)
            AND EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id=p.id AND v.enabled=1)",[$slug])->fetch();
        if (!$row) throw new RequestRejected(404,'not_found','Product not found.');
        return $this->hydrate([$row])[0];
    }
    public function adminDetail(int $actorId, int $id): array
    {
        CommerceAccess::requirePermission('admin.products.view',$actorId);
        $row=$this->query('SELECT * FROM products WHERE id=?',[$id])->fetch();
        if (!$row) throw new RequestRejected(404,'not_found','Product not found.');
        return $this->hydrate([$row],true)[0];
    }
}
