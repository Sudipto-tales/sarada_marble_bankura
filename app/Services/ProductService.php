<?php

final class ProductService
{
    public function __construct(private PDO $pdo, ?InventoryService $inventoryService = null) {}
    private function query(string $sql, array $values = []): PDOStatement { return DeveloperTrace::span(__METHOD__,fn()=>CommerceDatabase::query($this->pdo,$sql,$values)); }

    public static function advanceVersion(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Catalog version must advance inside its mutation transaction.');
        if (CommerceDatabase::query($pdo,"UPDATE catalog_versions SET version = version + 1 WHERE namespace = 'catalog'")->rowCount() !== 1) {
            throw new RuntimeException('Catalog version is unavailable.');
        }
    }

    public function save(int $actorId, array $input, int $expectedRevision, string $operationKey): array
    {
        CommerceAccess::requirePermission('admin.products.edit',$actorId);
        $operationKey = CommerceValues::key($operationKey);
        $id = isset($input['id']) ? CommerceValues::id($input['id']) : null;
        $status = 'active';
        if (!in_array($status,['draft','active','archived'],true)) CommerceValues::invalid('status');
        $data = ['slug'=>CommerceValues::slug($input['slug'] ?? null),'name'=>CommerceValues::text($input['name'] ?? null,190,'name'),
            'description'=>CommerceValues::text($input['description'] ?? '',10000,'description',true),
            'tags'=>CommerceValues::text($input['tags'] ?? '',1000,'tags',true),'category_id'=>CommerceValues::id($input['category_id'] ?? null,'category_id'),
            'brand_id'=>isset($input['brand_id']) && $input['brand_id'] !== '' ? CommerceValues::id($input['brand_id'],'brand_id') : null,
            'status'=>$status,'featured'=>CommerceValues::flag($input['featured'] ?? 0,'featured'),
            'advance_bps'=>($input['advance_bps'] ?? null) === null ? null : CommerceValues::integer($input['advance_bps'],0,10000,'advance_bps'),
            'price_minor'=>CommerceValues::integer($input['price_minor'] ?? null,0,10000000000,'price_minor'),
            'thickness_mm_milli'=>CommerceValues::integer($input['thickness_mm_milli'] ?? null,1,1000000000,'thickness_mm_milli'),
            'width_mm_milli'=>CommerceValues::integer($input['width_mm_milli'] ?? null,1,1000000000,'width_mm_milli'),
            'length_mm_milli'=>CommerceValues::integer($input['length_mm_milli'] ?? null,1,1000000000,'length_mm_milli'),
            'sell_unit'=>$input['sell_unit'] ?? 'sqft',
            'qty_increment_milli'=>CommerceValues::integer($input['qty_increment_milli'] ?? 1000,1,1000000000,'qty_increment_milli'),
            'coverage_sqft_milli'=>CommerceValues::integer($input['coverage_sqft_milli'] ?? null,1,1000000000,'coverage_sqft_milli')];
        try {
            return CommerceDatabase::transaction($this->pdo,function() use($actorId,$id,$data,$expectedRevision,$operationKey):array {
                CommerceAccess::requirePermission('admin.products.edit',$actorId);
                $lock = CommerceDatabase::lock($this->pdo);
                $existing = $id ? $this->query('SELECT * FROM products WHERE id = ?' . $lock,[$id])->fetch() : null;
                if ($id && !$existing) throw new RequestRejected(404,'not_found','Product not found.');
                if ($expectedRevision !== ($existing ? (int)$existing['revision'] : 0)) throw new RequestRejected(409,'revision_conflict','Product changed. Reload before saving.');
                $now = gmdate('Y-m-d\TH:i:s\Z');
                if ($id) {
                    $this->query('UPDATE products SET slug=?, name=?, description=?, tags=?, category_id=?, brand_id=?, status=?, featured=?, advance_bps=?, price_minor=?, thickness_mm_milli=?, width_mm_milli=?, length_mm_milli=?, sell_unit=?, qty_increment_milli=?, coverage_sqft_milli=?, revision=revision+1, updated_at=? WHERE id=?',array_merge(array_values($data),[$now,$id]));
                } else {
                    $this->query('INSERT INTO products (slug,name,description,tags,category_id,brand_id,status,featured,advance_bps,price_minor,thickness_mm_milli,width_mm_milli,length_mm_milli,sell_unit,qty_increment_milli,coverage_sqft_milli,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',array_merge(array_values($data),[$now,$now]));
                    $id = (int)$this->pdo->lastInsertId();
                }
                // Initialize inventory for this product (no variant_id needed)
                if (!$id) throw new RuntimeException('Product save failed - no ID returned.');
                $inv = $this->query('SELECT * FROM inventory WHERE product_id=?'.$lock,[$id])->fetchColumn();
                if (!$inv) {
                    $this->query('INSERT INTO inventory (product_id,on_hand_milli,reserved_milli,revision) VALUES (?,0,0,1)',[$id]);
                }
                self::advanceVersion($this->pdo);
                $revision = ($existing ? (int)$existing['revision'] : 0)+1;
                StaffAudit::record($this->pdo,$actorId,'product.save','product',$id,$operationKey,
                    ['revision'=>$revision,'status'=>$data['status'],'slug'=>$data['slug'],'price_minor'=>$data['price_minor'],'sell_unit'=>$data['sell_unit']]);
                return ['id'=>$id,'revision'=>$revision];
            });
        } catch (Throwable $e) {
            if (CommerceDatabase::uniqueConflict($e)) throw new RequestRejected(409,'catalog_conflict','A product slug already exists.');
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
        $hasType=array_key_exists('type_id',$input);
        $type=$hasType&&$input['type_id']!==null&&$input['type_id']!==''?CommerceValues::id($input['type_id']):null;
        $hasTax=array_key_exists('tax_bps',$input);$tax=$hasTax&&$input['tax_bps']!==null&&$input['tax_bps']!==''?CommerceValues::integer($input['tax_bps'],0,10000,'tax_bps'):null;
        $hasAdvance=array_key_exists('advance_bps',$input);$advance=$hasAdvance&&$input['advance_bps']!==null&&$input['advance_bps']!==''?CommerceValues::integer($input['advance_bps'],0,10000,'advance_bps'):null;
        try {
            return CommerceDatabase::transaction($this->pdo,function() use($actorId,$id,$slug,$name,$enabled,$hasType,$type,$hasTax,$tax,$hasAdvance,$advance,$key):int {
                CommerceAccess::requirePermission('admin.products.edit',$actorId);
                if (!$this->query('SELECT id FROM catalog_hierarchy_lock WHERE id=1'.CommerceDatabase::lock($this->pdo))->fetchColumn()) {
                    throw new RuntimeException('Category tree lock is unavailable.');
                }
                if($hasTax||$hasAdvance){
                    $prior=$this->query('SELECT target_id FROM admin_activity_log WHERE operation_key=?'.CommerceDatabase::lock($this->pdo),[$key])->fetch();
                    if($prior){
                        $target=(int)$prior['target_id'];
                        if($id!==null&&$id!==$target)throw new RequestRejected(409,'audit_key_conflict','Operation key has different inputs.');
                        $replay=['name'=>$name,'slug'=>$slug,'enabled'=>$enabled];if($hasType)$replay['type_id']=$type;if($hasTax)$replay['tax_bps']=$tax;if($hasAdvance)$replay['advance_bps']=$advance;
                        StaffAudit::record($this->pdo,$actorId,'category.save','category',$target,$key,$replay);
                        return $target;
                    }
                }
                if ($id && !$this->query('SELECT id FROM categories WHERE id=?',[$id])->fetchColumn()) throw new RequestRejected(404,'not_found','Category not found.');
                if ($type!==null && !$this->query('SELECT id FROM category_types WHERE id=?',[$type])->fetchColumn()) CommerceValues::invalid('type_id');
                if ($id) {
                    $this->query('UPDATE categories SET slug=?,name=?,enabled=? WHERE id=?',[$slug,$name,$enabled,$id]);
                    if($hasType)$this->query('UPDATE categories SET type_id=? WHERE id=?',[$type,$id]);
                } else {
                    $this->query('INSERT INTO categories (slug,name,enabled,type_id) VALUES (?,?,?,?)',[$slug,$name,$enabled,$type]); $id=(int)$this->pdo->lastInsertId();
                }
                if($hasTax)$this->query('UPDATE categories SET tax_bps=? WHERE id=?',[$tax,$id]);
                if($hasAdvance)$this->query('UPDATE categories SET advance_bps=? WHERE id=?',[$advance,$id]);
                self::advanceVersion($this->pdo);
                $changes=['name'=>$name,'slug'=>$slug,'enabled'=>$enabled];if($hasType)$changes['type_id']=$type;if($hasTax)$changes['tax_bps']=$tax;if($hasAdvance)$changes['advance_bps']=$advance;
                StaffAudit::record($this->pdo,$actorId,'category.save','category',$id,$key,$changes);
                return $id;
            });
        } catch (Throwable $e) { if (CommerceDatabase::uniqueConflict($e)) throw new RequestRejected(409,'catalog_conflict','Category slug already exists.'); throw $e; }
    }

    public function saveCategoryType(int $actorId, array $input, string $key): int
    {
        CommerceAccess::requirePermission('admin.products.edit',$actorId); $key=CommerceValues::key($key);
        $id=isset($input['id'])?CommerceValues::id($input['id']):null; $slug=CommerceValues::slug($input['slug']??null);
        $name=CommerceValues::text($input['name']??null,190,'name'); $enabled=CommerceValues::flag($input['enabled']??1,'enabled');
        $position=CommerceValues::integer($input['position']??0,0,100000,'position');
        try {
            return CommerceDatabase::transaction($this->pdo,function() use($actorId,$id,$slug,$name,$enabled,$position,$key):int {
                CommerceAccess::requirePermission('admin.products.edit',$actorId);
                if ($id && !$this->query('SELECT id FROM category_types WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetchColumn()) throw new RequestRejected(404,'not_found','Category type not found.');
                if ($id) $this->query('UPDATE category_types SET slug=?,name=?,enabled=?,position=? WHERE id=?',[$slug,$name,$enabled,$position,$id]);
                else { $this->query('INSERT INTO category_types (slug,name,enabled,position) VALUES (?,?,?,?)',[$slug,$name,$enabled,$position]); $id=(int)$this->pdo->lastInsertId(); }
                self::advanceVersion($this->pdo);
                StaffAudit::record($this->pdo,$actorId,'category_type.save','category_type',$id,$key,['name'=>$name,'slug'=>$slug,'enabled'=>$enabled,'position'=>$position]);
                return $id;
            });
        } catch (Throwable $e) { if (CommerceDatabase::uniqueConflict($e)) throw new RequestRejected(409,'catalog_conflict','Category type slug already exists.'); throw $e; }
    }

    public function saveBrand(int $actorId, array $input, string $key): int
    {
        CommerceAccess::requirePermission('admin.products.edit',$actorId); $key=CommerceValues::key($key);
        $id=isset($input['id'])?CommerceValues::id($input['id']):null; $slug=CommerceValues::slug($input['slug']??null);
        $name=CommerceValues::text($input['name']??null,190,'name'); $enabled=CommerceValues::flag($input['enabled']??1,'enabled');
        $logo=array_key_exists('logo_path',$input)?$input['logo_path']:false;
        if ($logo!==false && $logo!==null && !preg_match('#\Aassets/brands/[a-f0-9]{32}\.(jpg|png|webp)\z#',(string)$logo)) CommerceValues::invalid('logo_path');
        try {
            return CommerceDatabase::transaction($this->pdo,function() use($actorId,$id,$slug,$name,$enabled,$logo,$key):int {
                CommerceAccess::requirePermission('admin.products.edit',$actorId);
                if ($id && !$this->query('SELECT id FROM brands WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetchColumn()) throw new RequestRejected(404,'not_found','Brand not found.');
                if ($id) {
                    $this->query('UPDATE brands SET slug=?,name=?,enabled=? WHERE id=?',[$slug,$name,$enabled,$id]);
                    if ($logo!==false) $this->query('UPDATE brands SET logo_path=? WHERE id=?',[$logo,$id]);
                } else {
                    $this->query('INSERT INTO brands (slug,name,enabled,logo_path) VALUES (?,?,?,?)',[$slug,$name,$enabled,$logo===false?null:$logo]); $id=(int)$this->pdo->lastInsertId();
                }
                self::advanceVersion($this->pdo);
                StaffAudit::record($this->pdo,$actorId,'brand.save','brand',$id,$key,['name'=>$name,'slug'=>$slug,'enabled'=>$enabled,'logo'=>$logo===false?'unchanged':($logo===null?'cleared':'replaced')]);
                return $id;
            });
        } catch (Throwable $e) { if (CommerceDatabase::uniqueConflict($e)) throw new RequestRejected(409,'catalog_conflict','Brand slug already exists.'); throw $e; }
    }

    /** The only remote product-image form: a Google Drive file served by Google's image host at a fixed width. */
    public static function remoteImage($path): bool
    {
        return is_string($path) && preg_match('~\Ahttps?://[^\s/$.?#].[^\s]*\z~i', $path) === 1;
    }
    /** Normalise a Google Drive share link (or bare file ID) to the canonical remote image URL; rejects anything else. */
    public static function driveImage($link, int $width = 1200): string
    {
        $link = is_string($link) ? trim($link) : '';
        $id = null;
        if (preg_match('~\A[A-Za-z0-9_-]{20,80}\z~', $link)) $id = $link;
        elseif (preg_match('~\Ahttps://(?:drive\.google\.com/file/d/|lh3\.googleusercontent\.com/d/)([A-Za-z0-9_-]{20,80})(?:[/=?#].*)?\z~', $link, $m)) $id = $m[1];
        elseif (preg_match('~\Ahttps://(?:drive\.google.com/(?:open|uc|thumbnail)|drive\.usercontent\.google\.com/download)\?(?:[^#]*&)?id=([A-Za-z0-9_-]{20,80})(?:[&#].*)?\z~', $link, $m)) $id = $m[1];
        if ($id === null || $width < 16 || $width > 4000) return 'https://lh3.googleusercontent.com/d/placeholder=w' . $width;
        return 'https://lh3.googleusercontent.com/d/' . $id . '=w' . $width;
    }
    public function addImage(int $actorId, int $productId, array $image, string $key): int
    {
        CommerceAccess::requirePermission('admin.products.edit',$actorId); $key=CommerceValues::key($key);
        $path=$image['path']??null; $mime=$image['mime']??null;
        $formats=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!is_string($path)||!isset($formats[$mime])||(!self::remoteImage($path)&&!preg_match('~\Aassets/products/[a-f0-9]{32}\.' . $formats[$mime] . '\z~',$path))) CommerceValues::invalid('image');
        $width=CommerceValues::integer($image['width']??null,1,6000,'width'); $height=CommerceValues::integer($image['height']??null,1,6000,'height');
        $alt=CommerceValues::text($image['alt_text']??'',190,'alt_text',true); $position=CommerceValues::integer($image['position']??0,0,1000,'position');
        return CommerceDatabase::transaction($this->pdo,function() use($actorId,$productId,$path,$mime,$width,$height,$alt,$position,$key):int {
            CommerceAccess::requirePermission('admin.products.edit',$actorId);
            if (!$this->query('SELECT id FROM products WHERE id=?'.CommerceDatabase::lock($this->pdo),[$productId])->fetchColumn()) throw new RequestRejected(404,'not_found','Product not found.');
            if ((int)$this->query('SELECT COUNT(*) FROM product_images WHERE product_id=?',[$productId])->fetchColumn()>=12) CommerceValues::invalid('images');
            $this->query('INSERT INTO product_images (product_id,path,mime,width,height,position,alt_text) VALUES (?,?,?,?,?,?,?)',[$productId,$path,$mime,$width,$height,$position,$alt]);
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
            'category_types'=>$this->query('SELECT * FROM category_types'.$where.' ORDER BY position,name,id LIMIT 1000')->fetchAll(),
            'brands'=>$this->query('SELECT * FROM brands'.$where.' ORDER BY name,id LIMIT 1000')->fetchAll()];
    }

    public function categoryTypeImage(string $slug): string
    {
        $mapping = [
            'general' => '/assets/storefront/assets/textures/marble/bianco_dolomite.webp',
        ];
        return $mapping[$slug] ?? '/assets/storefront/assets/textures/marble/calacatta_gold.webp';
    }

    private function hydrate(array $products, bool $staff = false): array
    {
        return DeveloperTrace::span(__METHOD__,fn()=>$this->hydrateObserved($products,$staff));
    }
    private function hydrateObserved(array $products, bool $staff = false): array
    {
        if (!$products) return [];
        $ids=array_column($products,'id'); $marks=implode(',',array_fill(0,count($ids),'?'));
        // No variants - just load images per product
        $images=$this->query("SELECT * FROM product_images WHERE product_id IN ($marks) ORDER BY product_id,position,id",$ids)->fetchAll();
        $ratingGroups=[]; $imageGroups=[];
        foreach ($images as $image) $imageGroups[$image['product_id']][]=$image;
        foreach ($products as &$product) {
            $product['images']=$imageGroups[$product['id']]??[];
            $product['variants']=[];
            $product['in_stock'] = true;
            // Simple availability based on inventory on-hand stock per product
            $onHand = $this->query('SELECT COALESCE(SUM(on_hand_milli),0) FROM inventory WHERE product_id=?',[$product['id']])->fetchColumn();
            $product['availability_status'] = ($onHand > 0) ? 'in_stock' : 'out_of_stock';
            $product['in_stock'] = $product['availability_status'] === 'in_stock';
        }
        unset($product); return $products;
    }

    /** Bounded public selection, hydrated in bulk for wishlist/comparison. */
    public function selection(array $ids): array
    {
        if (count($ids) > 100) CommerceValues::invalid('products');
        $ids = array_values(array_unique(array_map([CommerceValues::class, 'id'], $ids)));
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->query("SELECT p.*,c.name AS category_name,c.slug AS category_slug,b.name AS brand_name,b.slug AS brand_slug
            FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id
            WHERE p.id IN ($marks) AND p.status='active' AND c.enabled=1 AND (p.brand_id IS NULL OR b.enabled=1) ORDER BY p.id", $ids)->fetchAll();
        return $this->hydrate($rows);
    }
    public function listing(array $filters = []): array { return DeveloperTrace::span(__METHOD__,fn()=>$this->listProducts($filters,null)); }
    /** Bounded public texture picker, using only published products and eligible gallery photos. */
    public function visualizerMaterials(?int $selectedId = null): array
    {
        if ($selectedId !== null) $selectedId = CommerceValues::id($selectedId);
        $image = "SELECT pi.path FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.position,pi.id LIMIT 1";
        return $this->query("SELECT p.id,p.name,p.slug,($image) AS image_path
            FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id
            WHERE p.status='active' AND c.enabled=1 AND (p.brand_id IS NULL OR b.enabled=1)
            AND EXISTS(SELECT 1 FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.position,pi.id LIMIT 1)
            ORDER BY CASE WHEN p.id=? THEN 0 ELSE 1 END,p.featured DESC,p.id DESC LIMIT 50", [$selectedId ?? 0])->fetchAll();
    }
    public function administration(int $actorId, array $filters = []): array
    {
        CommerceAccess::requirePermission('admin.products.view',$actorId); return $this->listProducts($filters,$actorId);
    }
    private function listProducts(array $filters, ?int $staffId): array
    {
        return DeveloperTrace::span(__METHOD__,fn()=>$this->listProductsObserved($filters,$staffId));
    }
    private function listProductsObserved(array $filters, ?int $staffId): array
    {
        $page=CommerceValues::integer($filters['page']??1,1,1000,'page'); $perPage=CommerceValues::integer($filters['per_page']??12,1,50,'per_page');
        $clauses=[]; $values=[];
        if ($staffId===null && isset($filters['q']) && $filters['q'] !== '') return $this->searchRanked($filters);
        if (isset($filters['brand'])&&$filters['brand']!=='') { $clauses[]='b.slug=?'; $values[]=CommerceValues::slug($filters['brand']); }
        if (isset($filters['category'])&&$filters['category']!=='') {
            $ids=$this->categoryTreeIds(CommerceValues::slug($filters['category']));
            $clauses[]='p.category_id IN ('.implode(',',array_fill(0,count($ids),'?')).')'; foreach($ids as $cid)$values[]=$cid;
        }
        if (isset($filters['type'])&&$filters['type']!=='') {
            $clauses[]='EXISTS(SELECT 1 FROM category_types ct WHERE ct.id=c.type_id AND ct.slug=?)'; $values[]=CommerceValues::slug($filters['type']);
        }
        if (isset($filters['q'])&&$filters['q']!=='') {
            $q=CommerceValues::text($filters['q'],80,'q'); $q='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';
            $clauses[]="(p.name LIKE ? ESCAPE '!' OR p.tags LIKE ? ESCAPE '!')"; $values[]=$q; $values[]=$q;
        }
        foreach (['min_price_minor'=>'>=','max_price_minor'=>'<='] as $name=>$compare) if (isset($filters[$name])&&$filters[$name]!=='') {
            $limit=CommerceValues::integer($filters[$name],0,CommerceValues::MAX_MONEY,$name);
            $clauses[]="(p.price_minor $compare $limit)";
        }
        if (isset($filters['in_stock']) && CommerceValues::flag($filters['in_stock'], 'in_stock') === 1) {
            $clauses[] = 'p.availability_status = "in_stock"';
        }
        if (isset($filters['min_rating']) && $filters['min_rating'] !== '') {
            $tenths = CommerceValues::integer($filters['min_rating'], 1, 5, 'min_rating') * 10;
            $clauses[] = '( '.self::RATING_AVG_SQL.' ) >= $tenths';
        }
        $sorts=['featured'=>'p.featured DESC,p.id DESC','newest'=>'p.id DESC','price_low'=>'p.price_minor ASC,p.id ASC','price_high'=>'p.price_minor DESC,p.id ASC',
            'rating_high'=>'('.self::RATING_AVG_SQL.') DESC,p.id DESC'];
        $sort=$filters['sort']??'featured'; if (!is_string($sort)||!isset($sorts[$sort])) CommerceValues::invalid('sort');
        $from=' FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id';
        $where=$clauses?' WHERE '.implode(' AND ',$clauses):'';
        $total=(int)$this->query('SELECT COUNT(*)'.$from.$where,$values)->fetchColumn();
        $offset=($page-1)*$perPage;
        $rows=$this->query('SELECT p.*,c.name AS category_name,c.slug AS category_slug,b.name AS brand_name,b.slug AS brand_slug'.$from.$where.' ORDER BY '.$sorts[$sort]." LIMIT $perPage OFFSET $offset",$values)->fetchAll();
        return ['items'=>$this->hydrate($rows,$staffId!==null),'page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>(int)ceil($total/$perPage)];
    }
    // Average approved rating (tenths, 0..50) for the outer product `p`, matching the hydrate() roll-up:
    private const RATING_AVG_SQL = "SELECT AVG(COALESCE(pr.rating_tenths,pr.rating*10)) FROM product_reviews pr "
        . "JOIN users_tbl pu ON pu.id=pr.user_id WHERE pr.product_id=p.id AND pr.status='approved' AND pu.status=1 AND pu.email_verify=1";
    private const CANDIDATE_CAP = 1000;
    // Weights (per-million) for the ranked score: relevance .40, net sales_30d .20, rating .15, stock .10, featured .10, freshness .05.
    private const WEIGHTS = ['relevance'=>400000,'sales'=>200000,'rating'=>150000,'featured'=>100000,'freshness'=>50000];

    /** Visibility + bounded filter clauses shared by the ranked search (keyword/sort handled separately). */
    private function searchClauses(array $filters): array
    {
        $clauses = ["p.status='active'", 'c.enabled=1', '(p.brand_id IS NULL OR b.enabled=1)',
            'EXISTS(SELECT 1 FROM product_images pi WHERE pi.product_id=p.id)'];
        $values = [];
        if (isset($filters['brand']) && $filters['brand'] !== '') { $clauses[] = 'b.slug=?'; $values[] = CommerceValues::slug($filters['brand']); }
        if (isset($filters['category']) && $filters['category'] !== '') {
            $ids = $this->categoryTreeIds(CommerceValues::slug($filters['category']));
            $clauses[] = 'p.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; foreach ($ids as $cid) $values[] = $cid;
        }
        if (isset($filters['type']) && $filters['type'] !== '') {
            $clauses[] = 'EXISTS(SELECT 1 FROM category_types ct WHERE ct.id=c.type_id AND ct.slug=?)'; $values[] = CommerceValues::slug($filters['type']);
        }
        foreach (['min_price_minor'=>'>=','max_price_minor'=>'<='] as $name => $cmp) if (isset($filters[$name]) && $filters[$name] !== '') {
            $limit = CommerceValues::integer($filters[$name], 0, CommerceValues::MAX_MONEY, $name);
            $clauses[] = "(p.price_minor $cmp $limit)";
        }
        if (isset($filters['in_stock']) && CommerceValues::flag($filters['in_stock'], 'in_stock') === 1) {
            $clauses[] = 'p.availability_status = "in_stock"';
        }
        return [$clauses, $values];
    }

    /**
     * SRCH-01 two-stage ranked keyword search. Stage 1 selects visible candidates (capped at 1000) with a
     * lexical score — MySQL FULLTEXT `MATCH … AGAINST` natural-language relevance, SQLite a bounded escaped
     * LIKE score. Stage 2 bulk-loads net sales_30d/rating/stock/featured/freshness, normalises each to [0,1]
     * against the candidate set and sorts by a deterministic integer weighted score, then product id. Candidate
     * caps make a large corpus approximate, so `capped`/`has_more` are exposed instead of a false exact total.
     */
    private function searchRanked(array $filters): array
    {
        $page = CommerceValues::integer($filters['page'] ?? 1, 1, 1000, 'page');
        $perPage = CommerceValues::integer($filters['per_page'] ?? 12, 1, 50, 'per_page');
        $q = CommerceValues::text($filters['q'], 80, 'q');
        [$clauses, $values] = $this->searchClauses($filters);
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $from = ' FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id';
        if ($mysql) {
            $match = 'MATCH(p.name,p.description,p.tags) AGAINST(? IN NATURAL LANGUAGE MODE)';
            $selectBinds = [$q]; $whereBinds = [$q];
            $select = "SELECT p.id,($match) AS lex" . $from . ' WHERE ' . implode(' AND ', $clauses) . " AND $match ORDER BY lex DESC,p.id ASC LIMIT " . (self::CANDIDATE_CAP + 1);
        } else {
            // Bounded escaped LIKE scoring: name 3, tags 2, description 1. Never concatenate uncontrolled input.
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $score = "((CASE WHEN p.name LIKE ? ESCAPE '!' THEN 3 ELSE 0 END)+(CASE WHEN p.tags LIKE ? ESCAPE '!' THEN 2 ELSE 0 END)+(CASE WHEN p.description LIKE ? ESCAPE '!' THEN 1 ELSE 0 END))";
            $where = "(p.name LIKE ? ESCAPE '!' OR p.tags LIKE ? ESCAPE '!' OR p.description LIKE ? ESCAPE '!')";
            $selectBinds = [$pattern, $pattern, $pattern]; $whereBinds = [$pattern, $pattern, $pattern];
            $select = "SELECT p.id,$score AS lex" . $from . ' WHERE ' . implode(' AND ', $clauses) . " AND $where ORDER BY lex DESC,p.id ASC LIMIT " . (self::CANDIDATE_CAP + 1);
        }
        $candidates = $this->query($select, array_merge($selectBinds, $values, $whereBinds))->fetchAll();
        $capped = count($candidates) > self::CANDIDATE_CAP;
        if ($capped) array_pop($candidates);
        if (!$candidates) return ['items'=>[], 'page'=>$page, 'per_page'=>$perPage, 'total'=>0, 'pages'=>0, 'capped'=>false, 'has_more'=>false, 'generation'=>$this->searchGeneration($q)];
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $candidates);
        $lex = []; foreach ($candidates as $r) $lex[(int) $r['id']] = (float) $r['lex'];

        // Stage 2 signals, bulk loaded for the candidate set only.
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $cutoff = gmdate('Y-m-d', CommerceDatabase::clock($this->pdo) - 30 * 86400);
        $sales = []; foreach ($this->query("SELECT product_id,COALESCE(SUM(orders_net),0) AS s FROM product_daily_metrics WHERE product_id IN ($marks) AND metric_date>? GROUP BY product_id", array_merge($ids, [$cutoff]))->fetchAll() as $row) $sales[(int) $row['product_id']] = max(0, (int) $row['s']);
        $rows = $this->query("SELECT p.*,c.name AS category_name,c.slug AS category_slug,b.name AS brand_name,b.slug AS brand_slug
            FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id WHERE p.id IN ($marks)", $ids)->fetchAll();
        $products = $this->hydrate($rows);

        $maxLex = max($lex) ?: 0.0; $maxSales = $sales ? max($sales) : 0; $now = CommerceDatabase::clock($this->pdo);
        foreach ($products as &$product) {
            $id = (int) $product['id'];
            $age = max(0, ($now - (int) strtotime((string) $product['created_at'])) / 86400);
            $norm = [
                'relevance' => $maxLex > 0 ? ($lex[$id] ?? 0) / $maxLex : 0.0,
                'sales' => $maxSales > 0 ? ($sales[$id] ?? 0) / $maxSales : 0.0,
                'rating' => $product['rating_tenths'] !== null ? $product['rating_tenths'] / 50 : 0.0,
                'stock' => $product['availability_status'] === 'in_stock' ? 1.0 : 0.0,
                'featured' => (int) $product['featured'] ? 1.0 : 0.0,
                'freshness' => max(0.0, 1 - $age / 90),
            ];
            $sum = 0; foreach (self::WEIGHTS as $factor => $weight) $sum += (int) round(min(1.0, max(0.0, $norm[$factor])) * $weight);
            $product['search_score'] = $sum;
        }
        unset($product);
        // Deterministic order: score descending, then product id ascending for stable ties and pagination.
        usort($products, static fn(array $a, array $b): int => $b['search_score'] <=> $a['search_score'] ?: (int) $a['id'] <=> (int) $b['id']);
        $total = count($products); $pages = (int) ceil($total / $perPage);
        return ['items'=>array_slice($products, ($page - 1) * $perPage, $perPage), 'page'=>$page, 'per_page'=>$perPage,
            'total'=>$total, 'pages'=>$pages, 'capped'=>$capped, 'has_more'=>$page < $pages, 'generation'=>$this->searchGeneration($q)];
    }

    /** A stable generation token (catalog version + UTC day + normalised query) so pagination is consistent. */
    private function searchGeneration(string $q): string
    {
        $version = (int) $this->query("SELECT version FROM catalog_versions WHERE namespace='catalog'")->fetchColumn();
        return $version . '-' . gmdate('Y-m-d', CommerceDatabase::clock($this->pdo)) . '-' . substr(hash('sha256', mb_strtolower($q)), 0, 12);
    }

    public function detail(string $slug): array
    {
        return DeveloperTrace::span(__METHOD__,fn()=>$this->detailObserved($slug));
    }
    private function detailObserved(string $slug): array
    {
        $slug=CommerceValues::slug($slug);
        $row=$this->query("SELECT p.*,c.name AS category_name,c.slug AS category_slug,b.name AS brand_name,b.slug AS brand_slug
            FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id
            WHERE p.slug=? AND p.status='active' AND c.enabled=1 AND (p.brand_id IS NULL OR b.enabled=1)",[$slug])->fetch();
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