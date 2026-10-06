<?php

final class CatalogReadService
{
    private ProductService $products;
    public function __construct(private PDO $pdo,private ?Cache $cache=null) {$this->products=new ProductService($pdo);$this->cache??=new Cache();}
    public static function filters(array $input):array
    {
        $filters=['page'=>CommerceValues::integer($input['page']??1,1,1000,'page'),'per_page'=>CommerceValues::integer($input['per_page']??12,1,50,'per_page'),'sort'=>$input['sort']??'featured'];
        if(!is_string($filters['sort'])||!in_array($filters['sort'],['featured','newest','price_low','price_high'],true))CommerceValues::invalid('sort');
        foreach(['category','brand'] as $field)if(isset($input[$field])&&$input[$field]!=='')$filters[$field]=CommerceValues::slug($input[$field]);
        if(isset($input['q'])&&$input['q']!=='')$filters['q']=CommerceValues::text($input['q'],80,'q');
        foreach(['min_price_minor','max_price_minor'] as $field)if(isset($input[$field])&&$input[$field]!=='')$filters[$field]=CommerceValues::integer($input[$field],0,CommerceValues::MAX_MONEY,$field);
        if(isset($filters['min_price_minor'],$filters['max_price_minor'])&&$filters['min_price_minor']>$filters['max_price_minor'])CommerceValues::invalid('price_range');
        ksort($filters);return $filters;
    }
    private function read(array $parameters,callable $loader):array
    {
        // Never publish from a transaction snapshot or write a file while holding SQL locks.
        if($this->pdo->inTransaction())return $loader();
        try {$version=(int)CommerceDatabase::query($this->pdo,"SELECT version FROM catalog_versions WHERE namespace='catalog'")->fetchColumn();}
        catch(PDOException $e){return $loader();}
        if($version<1)return $loader();
        $parameters+=['contract'=>'public-v1','currency'=>'INR','locale'=>'en-IN'];
        $cached=$this->cache->get($version,$parameters);if($cached!==null)return $cached;
        $result=$loader();$this->cache->put($version,$parameters,$result);return $result;
    }
    public function listing(array $input=[]):array
    {
        $filters=self::filters($input);return $this->read(['resource'=>'listing',...$filters],fn()=>$this->products->listing($filters));
    }
    public function detail(string $slug):array
    {
        $slug=CommerceValues::slug($slug);return $this->read(['resource'=>'detail','slug'=>$slug],fn()=>$this->products->detail($slug));
    }
}
