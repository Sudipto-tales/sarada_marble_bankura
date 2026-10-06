<?php

/** Best-effort public read cache. No identity, grants, cart or order authority lives here. */
final class Cache
{
    private ?string $directory=null;
    private $redis=null;
    private bool $initialized=false;
    public array $stats=['hit'=>0,'miss'=>0,'failure'=>0];
    private const MAX_BYTES=1048576;
    private function initialize():void
    {
        if($this->initialized)return;$this->initialized=true;
        if(env('CACHE_DRIVER','file')==='none')return;
        try {$this->directory=PrivateStorage::directory('cache');}catch(Throwable $e){$this->stats['failure']++;}
        if(env('CACHE_DRIVER','file')==='redis') {
            try {
                if(!class_exists('Redis'))throw new RuntimeException('Redis extension unavailable.');
                $host=(string)env('CACHE_REDIS_HOST','127.0.0.1');$port=CommerceValues::integer((string)env('CACHE_REDIS_PORT','6379'),1,65535,'redis_port');
                if($host===''||preg_match('/[\x00-\x20]/',$host))throw new RuntimeException('Invalid cache host.');
                $redis=new Redis();$redis->connect($host,$port,0.05);$redis->setOption(Redis::OPT_READ_TIMEOUT,0.05);
                $password=(string)env('CACHE_REDIS_PASSWORD','');if($password!==''&&!$redis->auth($password))throw new RuntimeException('Cache authentication unavailable.');
                $this->redis=$redis;
            }catch(Throwable $e){$this->redis=null;$this->stats['failure']++;}
        }
    }
    public static function key(int $version,array $parameters):string
    {
        if($version<1)throw new InvalidArgumentException('Invalid cache version.');
        $allowed=['resource','contract','page','per_page','sort','category','brand','q','min_price_minor','max_price_minor','slug','currency','locale','generation'];
        if(array_diff(array_keys($parameters),$allowed))throw new InvalidArgumentException('Private or unknown cache key field.');
        foreach($parameters as $value)if(!is_int($value)&&!is_string($value))throw new InvalidArgumentException('Invalid cache key value.');
        ksort($parameters);return hash('sha256',json_encode(['namespace'=>'catalog','version'=>$version,'parameters'=>$parameters],JSON_THROW_ON_ERROR));
    }
    private function safe($value,int $depth=0):bool
    {
        if($depth>16)return false;
        if(is_array($value)) {
            foreach($value as $key=>$item) {
                if(is_string($key)&&preg_match('/(?:password|secret|token|csrf|cookie|session|user_id|email|address|permissions|principal)/i',$key))return false;
                if(!$this->safe($item,$depth+1))return false;
            }return true;
        }return $value===null||is_string($value)||is_int($value)||is_bool($value);
    }
    private function decode(?string $raw,int $version):?array
    {
        if($raw===null||strlen($raw)>self::MAX_BYTES)return null;
        try {$record=json_decode($raw,true,24,JSON_THROW_ON_ERROR);}catch(Throwable $e){return null;}
        if(!is_array($record)||($record['version']??null)!==$version||!is_int($record['expires_at']??null)||$record['expires_at']<=time()
            ||!is_array($record['payload']??null)||!$this->safe($record['payload']))return null;
        return $record['payload'];
    }
    private function validPayload(array $parameters,array $payload):bool
    {
        $resource=$parameters['resource']??null;
        if($resource==='listing') {
            foreach(['page','per_page','total','pages'] as $field)if(!is_int($payload[$field]??null)||$payload[$field]<0)return false;
            if(!is_array($payload['items']??null)||count($payload['items'])>50)return false;$products=$payload['items'];
        }elseif($resource==='detail')$products=[$payload];else return false;
        foreach($products as $product) {
            if(!is_array($product)||!isset($product['id'],$product['name'],$product['slug'])||!is_string($product['name'])||!is_string($product['slug'])
                ||!is_array($product['variants']??null)||!is_array($product['images']??null)||!is_bool($product['in_stock']??null))return false;
            foreach($product['variants'] as $variant)if(!is_array($variant)||!isset($variant['id'],$variant['unit_price_minor'],$variant['qty_increment_milli'],$variant['sell_unit']))return false;
        }return true;
    }
    public function get(int $version,array $parameters):?array
    {
        $key=self::key($version,$parameters);$this->initialize();$payload=null;
        if($this->redis) {
            try {$raw=$this->redis->get('sarada:catalog:'.$key);$payload=$this->decode(is_string($raw)?$raw:null,$version);}
            catch(Throwable $e){$this->redis=null;$this->stats['failure']++;}
        }
        if($payload===null&&$this->directory) {
            $path=$this->directory.'/'.$key.'.json';
            if(is_file($path)&&!is_link($path)) {
                $size=@filesize($path);$raw=$size!==false&&$size<=self::MAX_BYTES?@file_get_contents($path):false;
                $payload=$this->decode(is_string($raw)?$raw:null,$version);
                if($payload===null)@unlink($path);
            }
        }
        if($payload!==null&&!$this->validPayload($parameters,$payload))$payload=null;
        $this->stats[$payload===null?'miss':'hit']++;return $payload;
    }
    public function put(int $version,array $parameters,array $payload,int $ttl=120):bool
    {
        $key=self::key($version,$parameters);$ttl=CommerceValues::integer($ttl,1,900,'cache_ttl');
        if(!$this->safe($payload))throw new InvalidArgumentException('Private payload cannot enter public cache.');
        if(!$this->validPayload($parameters,$payload))return false;
        try {$raw=json_encode(['version'=>$version,'expires_at'=>time()+$ttl,'payload'=>$payload],JSON_THROW_ON_ERROR);}
        catch(Throwable $e){$this->stats['failure']++;return false;}
        if(strlen($raw)>self::MAX_BYTES)return false;$this->initialize();$saved=false;
        if($this->redis) {
            try {$saved=(bool)$this->redis->setex('sarada:catalog:'.$key,$ttl,$raw);}
            catch(Throwable $e){$this->redis=null;$this->stats['failure']++;}
        }
        if($this->directory) {
            $temporary=$this->directory.'/tmp-'.bin2hex(random_bytes(16));$mask=umask(0077);
            try {
                $handle=@fopen($temporary,'xb');if(!$handle)throw new RuntimeException('Cache write unavailable.');
                try {$bytes=fwrite($handle,$raw);if($bytes!==strlen($raw)||!fflush($handle))throw new RuntimeException('Cache write unavailable.');}
                finally {fclose($handle);}
                if(!@rename($temporary,$this->directory.'/'.$key.'.json'))throw new RuntimeException('Cache publish unavailable.');$saved=true;
            }catch(Throwable $e){$this->stats['failure']++;}
            finally {@unlink($temporary);umask($mask);}
        }return $saved;
    }
    /** Inspect at most limit entries, with a monotonic runtime cap. Temp writers are left alone. */
    public function cleanup(int $limit=100,int $seconds=2):int
    {
        $limit=CommerceValues::integer($limit,1,1000,'cache_cleanup_limit');$seconds=CommerceValues::integer($seconds,1,10,'cache_cleanup_seconds');$this->initialize();if(!$this->directory)return 0;
        $end=hrtime(true)+$seconds*1000000000;$inspected=0;$removed=0;
        foreach(new DirectoryIterator($this->directory) as $file) {
            if($file->isDot())continue;if(++$inspected>$limit||hrtime(true)>$end)break;
            if($file->isLink()||!$file->isFile()||!preg_match('/\A[a-f0-9]{64}\.json\z/',$file->getFilename()))continue;
            $raw=$file->getSize()<=self::MAX_BYTES?@file_get_contents($file->getPathname()):false;
            try {$record=is_string($raw)?json_decode($raw,true,24,JSON_THROW_ON_ERROR):null;}catch(Throwable $e){$record=null;}
            if(!is_array($record)||!is_int($record['expires_at']??null)||$record['expires_at']<=time())if(@unlink($file->getPathname()))$removed++;
        }return $removed;
    }
}
