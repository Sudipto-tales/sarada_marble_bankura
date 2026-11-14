<?php

/** Raster uploads keep their original bytes; caller-supplied names/paths are never persisted. */
final class ProductImageService
{
    public function __construct(private PDO $pdo) {}
    public static function available(): bool
    {
        return function_exists('finfo_open')&&function_exists('getimagesizefromstring')
            &&((class_exists('Imagick')&&extension_loaded('imagick'))
                ||(extension_loaded('gd')&&function_exists('imagecreatefromstring')));
    }
    /** URL import additionally needs cURL for the SSRF-guarded fetch. */
    public static function urlImportAvailable(): bool
    {
        return self::available()&&function_exists('curl_init');
    }
    public function upload(int $actor,int $product,array $file,array $input,string $key): int
    {
        CommerceAccess::requirePermission('admin.products.edit',$actor);
        $stored=$this->store($file,'assets/products');
        return $this->attach($actor,$product,$stored,$input,$key);
    }
    /** Fetch an operator-supplied image URL under SSRF guards, preserve its bytes, and attach it like an upload. */
    public function uploadFromUrl(int $actor,int $product,string $url,array $input,string $key): int
    {
        CommerceAccess::requirePermission('admin.products.edit',$actor);
        $stored=$this->storeBytes($this->fetchImage($url),'assets/products');
        return $this->attach($actor,$product,$stored,$input,$key);
    }
    /** Persist a stored raster against the product, cleaning up the generated file if the record is rejected. */
    private function attach(int $actor,int $product,array $stored,array $input,string $key): int
    {
        $alt=CommerceValues::text($input['alt_text']??'',190,'alt_text',true);
        $position=CommerceValues::integer($input['position']??0,0,1000,'position');
        $variant=($input['variant_id']??'')===''?null:CommerceValues::id($input['variant_id'],'variant_id');
        try{
            return (new ProductService($this->pdo))->addImage($actor,$product,['path'=>$stored['path'],'mime'=>$stored['mime'],'width'=>$stored['width'],'height'=>$stored['height'],'alt_text'=>$alt,'position'=>$position,'variant_id'=>$variant],$key);
        }catch(Throwable $e){$this->discard($stored['path']);throw $e;}
    }
    /** Brand tile logo: preserve its validated source bytes beside product rasters. */
    public function brandLogo(int $actor,array $file): string
    {
        CommerceAccess::requirePermission('admin.products.edit',$actor);
        return $this->store($file,'assets/brands')['path'];
    }
    /** Remove a generated raster this service wrote; unknown paths are ignored. */
    public function discard(?string $path): void
    {
        if(!is_string($path)||!preg_match('#\Aassets/(products|brands)/[a-f0-9]{32}\.(jpg|png|webp)\z#',$path))return;
        $file=dirname(__DIR__,2).'/'.$path;
        if(is_file($file)&&!is_link($file))@unlink($file);
    }
    /** Validate an uploaded raster and publish its original bytes under a generated name. */
    private function store(array $file,string $folder): array
    {
        if(($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))CommerceValues::invalid('image_upload');
        $size=filesize($file['tmp_name']);
        if(!$size||$size>4*1024*1024)throw new RequestRejected(422,'invalid_image','Choose a JPEG, PNG or WebP under 4 MB.');
        $bytes=file_get_contents($file['tmp_name']);if(!is_string($bytes))CommerceValues::invalid('image_upload');
        $info=$this->inspectBytes($bytes);
        // Upload-only defense in depth: the client-declared extension must agree with the sniffed type.
        $formats=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $extension=strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION));
        if(!in_array($extension,['jpg','jpeg','png','webp'],true)||($extension==='jpeg'?'jpg':$extension)!==$formats[$info['mime']])CommerceValues::invalid('image_type');
        return $this->publishBytes($bytes,$info,$folder);
    }
    /** Inspect and fully decode accepted bytes without changing them. Shared by upload and URL import. */
    private function inspectBytes(string $bytes): array
    {
        if(!self::available())throw new RequestRejected(503,'image_decoder_unavailable','Image handling needs PHP Imagick or GD and the fileinfo extension. Ask the server operator to enable one of those image extensions.');
        $size=strlen($bytes);
        if(!$size||$size>4*1024*1024)throw new RequestRejected(422,'invalid_image','Choose a JPEG, PNG or WebP under 4 MB.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))CommerceValues::invalid('image_type');
        $dimensions=@getimagesizefromstring($bytes);
        if(!$dimensions||$dimensions[0]<1||$dimensions[1]<1||$dimensions[0]>6000||$dimensions[1]>6000||$dimensions[0]*$dimensions[1]>12000000)CommerceValues::invalid('image_dimensions');
        if(class_exists('Imagick')&&extension_loaded('imagick')){
            $decoded=new Imagick();
            try{
                $decoded->readImageBlob($bytes);
                if($decoded->getNumberImages()!==1||!in_array(strtolower($decoded->getImageFormat()),['jpeg','jpg','png','webp'],true)
                    ||$decoded->getImageWidth()!==$dimensions[0]||$decoded->getImageHeight()!==$dimensions[1])CommerceValues::invalid('image_decode');
            }catch(RequestRejected $e){throw $e;}catch(Throwable $e){CommerceValues::invalid('image_decode');}
            finally{$decoded->clear();$decoded->destroy();}
        }else{
            $decoded=@imagecreatefromstring($bytes);
            if(!$decoded)CommerceValues::invalid('image_decode');
            imagedestroy($decoded);
        }
        return ['width'=>$dimensions[0],'height'=>$dimensions[1],'mime'=>$mime];
    }
    /** Stage and publish exact validated source bytes under a random filename and matching extension. */
    private function storeBytes(string $bytes,string $folder): array
    {
        return $this->publishBytes($bytes,$this->inspectBytes($bytes),$folder);
    }
    private function publishBytes(string $bytes,array $info,string $folder): array
    {
        $stage=tempnam(PrivateStorage::directory('image-staging'),'raster-');$public=null;
        $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime']];
        try{
            if(file_put_contents($stage,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Could not stage image.');
            chmod($stage,0600);
            $name=bin2hex(random_bytes(16)).'.'.$extension;$relative=$folder.'/'.$name;
            $directory=dirname(__DIR__,2).'/'.$folder;
            if(is_link($directory)||(!is_dir($directory)&&!mkdir($directory,0755,true)))throw new RuntimeException('Image storage is unavailable.');
            $public=$directory.'/'.$name;
            if(!copy($stage,$public)||!chmod($public,0644))throw new RuntimeException('Image storage is unavailable.');
            return ['path'=>$relative,'width'=>$info['width'],'height'=>$info['height'],'mime'=>$info['mime']];
        }catch(Throwable $e){if($public&&is_file($public))unlink($public);throw $e;}
        finally{if(is_file($stage))unlink($stage);}
    }
    /** Parse and SSRF-screen an operator-supplied image URL. Returns the host/port/pinned-IP to fetch, or
        throws: http(s) only, no embedded credentials, and every resolved address must be public. */
    public static function validateUrl(string $url): array
    {
        $url=trim($url);
        if($url===''||strlen($url)>2000)CommerceValues::invalid('image_url');
        $parts=parse_url($url);
        if(!is_array($parts)||!isset($parts['scheme'],$parts['host'])||isset($parts['user'])||isset($parts['pass'])
            ||!in_array(strtolower($parts['scheme']),['http','https'],true))CommerceValues::invalid('image_url');
        $scheme=strtolower($parts['scheme']);$host=$parts['host'];
        $ips=self::resolveHost($host);
        if(!$ips)throw new RequestRejected(422,'image_url_unreachable','That image host could not be resolved.');
        foreach($ips as $ip)if(!self::publicIp($ip))throw new RequestRejected(422,'image_url_blocked','That image URL points to a non-public address.');
        return ['url'=>$url,'host'=>$host,'port'=>(int)($parts['port']??($scheme==='https'?443:80)),'ip'=>$ips[0]];
    }
    /** Resolve a host to IP literals; a literal IP is returned as-is (so private literals are screened too). */
    private static function resolveHost(string $host): array
    {
        $literal=trim($host,'[]');
        if(filter_var($literal,FILTER_VALIDATE_IP))return [$literal];
        $records=@dns_get_record($host,DNS_A|DNS_AAAA)?:[];
        $ips=[];
        foreach($records as $record){if(!empty($record['ip']))$ips[]=$record['ip'];if(!empty($record['ipv6']))$ips[]=$record['ipv6'];}
        return array_values(array_unique($ips));
    }
    private static function publicIp(string $ip): bool
    {
        return (bool)filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
    }
    /** Fetch the screened URL's bytes with cURL: pinned IP (no DNS rebinding), no redirects, bounded size/time. */
    private function fetchImage(string $url): string
    {
        if(!function_exists('curl_init'))throw new RequestRejected(503,'image_fetch_unavailable','Fetching images by URL needs the PHP cURL extension. Upload a file instead.');
        $target=self::validateUrl($url);
        $max=4*1024*1024;$buffer='';$aborted=false;
        $handle=curl_init();
        curl_setopt_array($handle,[
            CURLOPT_URL=>$target['url'],
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=>0,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>15,
            CURLOPT_MAXFILESIZE=>$max,
            CURLOPT_USERAGENT=>'SaradaMarbleImageBot/1.0',
            // Pin the already-screened address so DNS cannot be rebound to a private host between check and fetch.
            CURLOPT_RESOLVE=>[$target['host'].':'.$target['port'].':'.$target['ip']],
            CURLOPT_WRITEFUNCTION=>function($channel,$chunk)use(&$buffer,&$aborted,$max):int{
                $buffer.=$chunk;
                if(strlen($buffer)>$max){$aborted=true;return 0;}
                return strlen($chunk);
            },
        ]);
        $ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
        if($aborted)throw new RequestRejected(422,'invalid_image','Choose a JPEG, PNG or WebP under 4 MB.');
        if($ok===false||$status<200||$status>=300)throw new RequestRejected(422,'image_url_unreachable','That image URL could not be fetched (redirects are not followed).');
        if($buffer==='')CommerceValues::invalid('image_url');
        return $buffer;
    }
    /** Operator CLI only: inspect/remove old unreferenced generated files in bounded batches. */
    public function reconcile(bool $delete=false): array
    {
        if(PHP_SAPI!=='cli')throw new LogicException('Image reconciliation is CLI only.');
        $directory=dirname(__DIR__,2).'/assets/products';
        $handle=fopen(PrivateStorage::directory('maintenance').'/product-images.cursor','c+');
        if(!$handle||!flock($handle,LOCK_EX|LOCK_NB))throw new RuntimeException('Image maintenance is already running.');
        try{
            $offset=$delete?max(0,(int)stream_get_contents($handle)):0;
            $iterator=new DirectoryIterator($directory);$scanned=0;$orphans=0;$removed=0;$deadline=microtime(true)+5;
            try{$iterator->seek($offset);}catch(OutOfBoundsException $e){$offset=0;$iterator->rewind();}
            while($iterator->valid()&&$scanned<400&&microtime(true)<$deadline){
                $entry=$iterator->current();$scanned++;$offset++;
                if($entry->isFile()&&!$entry->isLink()&&preg_match('/\A[a-f0-9]{32}\.(png|jpg|webp)\z/',$entry->getFilename())&&$entry->getMTime()<time()-86400){
                    $path='assets/products/'.$entry->getFilename();
                    if(!(int)CommerceDatabase::query($this->pdo,'SELECT COUNT(*) FROM product_images WHERE path=?',[$path])->fetchColumn()){
                        $orphans++;if($delete&&unlink($entry->getPathname()))$removed++;
                    }
                }
                $iterator->next();
            }
            if($delete){if(!$iterator->valid())$offset=0;rewind($handle);ftruncate($handle,0);fwrite($handle,(string)$offset);fflush($handle);}
            $stagingOrphans=0;$stagingRemoved=0;
            foreach(new DirectoryIterator(PrivateStorage::directory('image-staging')) as $entry){
                if($scanned>=500||microtime(true)>=$deadline)break;$scanned++;
                if($entry->isFile()&&!$entry->isLink()&&preg_match('/\A raster-[A-Za-z0-9]+\z/x',$entry->getFilename())&&$entry->getMTime()<time()-86400){
                    $stagingOrphans++;if($delete&&unlink($entry->getPathname()))$stagingRemoved++;
                }
            }
            return compact('scanned','orphans','removed','stagingOrphans','stagingRemoved');
        }finally{flock($handle,LOCK_UN);fclose($handle);}
    }
    public function remove(int $actor,int $product,int $image,string $key): void
    {
        CommerceAccess::requirePermission('admin.products.edit',$actor);$key=CommerceValues::key($key);
        $path=CommerceDatabase::transaction($this->pdo,function()use($actor,$product,$image,$key){
            CommerceAccess::requirePermission('admin.products.edit',$actor);
            if(!CommerceDatabase::query($this->pdo,'SELECT id FROM products WHERE id=?'.CommerceDatabase::lock($this->pdo),[$product])->fetchColumn())throw new RequestRejected(404,'not_found','Product not found.');
            $row=CommerceDatabase::query($this->pdo,'SELECT path FROM product_images WHERE id=? AND product_id=?',[$image,$product])->fetch();
            if(!$row)throw new RequestRejected(404,'not_found','Image not found.');
            CommerceDatabase::query($this->pdo,'DELETE FROM product_images WHERE id=? AND product_id=?',[$image,$product]);
            ProductService::advanceVersion($this->pdo);StaffAudit::record($this->pdo,$actor,'image.remove','product',$product,$key,['image_id'=>$image]);
            return $row['path'];
        });
        if(preg_match('~\Aassets/products/[a-f0-9]{32}\.(jpg|png|webp)\z~',$path)&&!(int)CommerceDatabase::query($this->pdo,'SELECT COUNT(*) FROM product_images WHERE path=?',[$path])->fetchColumn()){
            $file=dirname(__DIR__,2).'/'.$path;if(is_file($file)&&!is_link($file))@unlink($file);
        }
    }
}
