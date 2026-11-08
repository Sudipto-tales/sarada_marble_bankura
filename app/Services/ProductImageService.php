<?php

/** Raster uploads are re-encoded; caller-supplied names/paths are never persisted. */
final class ProductImageService
{
    public function __construct(private PDO $pdo) {}
    public static function available(): bool
    {
        return extension_loaded('gd')&&function_exists('imagecreatefromstring')&&function_exists('imagepng')&&function_exists('finfo_open');
    }
    public function upload(int $actor,int $product,array $file,array $input,string $key): int
    {
        CommerceAccess::requirePermission('admin.products.edit',$actor);
        $alt=CommerceValues::text($input['alt_text']??'',190,'alt_text');
        $position=CommerceValues::integer($input['position']??0,0,1000,'position');
        $variant=($input['variant_id']??'')===''?null:CommerceValues::id($input['variant_id'],'variant_id');
        $stored=$this->store($file,'assets/products');
        try{
            return (new ProductService($this->pdo))->addImage($actor,$product,['path'=>$stored['path'],'mime'=>'image/png','width'=>$stored['width'],'height'=>$stored['height'], 'alt_text'=>$alt,'position'=>$position,'variant_id'=>$variant],$key);
        }catch(Throwable $e){$this->discard($stored['path']);throw $e;}
    }
    /** Brand tile logo: same re-encode pipeline, stored beside product rasters under its own folder. */
    public function brandLogo(int $actor,array $file): string
    {
        CommerceAccess::requirePermission('admin.products.edit',$actor);
        return $this->store($file,'assets/brands')['path'];
    }
    /** Remove a generated raster this service wrote; unknown paths are ignored. */
    public function discard(?string $path): void
    {
        if(!is_string($path)||!preg_match('#\Aassets/(products|brands)/[a-f0-9]{32}\.png\z#',$path))return;
        $file=dirname(__DIR__,2).'/'.$path;
        if(is_file($file)&&!is_link($file))@unlink($file);
    }
    /**
     * Validate an uploaded raster, re-encode it to PNG and publish it under $folder.
     * Returns the relative public path and the source pixel dimensions.
     */
    private function store(array $file,string $folder): array
    {
        if(!self::available())throw new RequestRejected(503,'image_decoder_unavailable','Image uploads need the PHP GD extension. Ask the server operator to enable it.');
        if(($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))CommerceValues::invalid('image_upload');
        $size=filesize($file['tmp_name']);
        if(!$size||$size>4*1024*1024)throw new RequestRejected(422,'invalid_image','Choose a JPEG, PNG or WebP under 4 MB.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $formats=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $extension=strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION));
        if(!isset($formats[$mime])||!in_array($extension,['jpg','jpeg','png','webp'],true)||($extension==='jpeg'?'jpg':$extension)!==$formats[$mime])CommerceValues::invalid('image_type');
        $dimensions=@getimagesize($file['tmp_name']);
        if(!$dimensions||$dimensions[0]<1||$dimensions[1]<1||$dimensions[0]>6000||$dimensions[1]>6000||$dimensions[0]*$dimensions[1]>12000000)CommerceValues::invalid('image_dimensions');
        $bytes=file_get_contents($file['tmp_name']);$decoded=@imagecreatefromstring($bytes);
        if(!$decoded)CommerceValues::invalid('image_decode');
        $stage=tempnam(PrivateStorage::directory('image-staging'),'raster-');$public=null;
        try{
            // Keep transparency: brand logos are usually PNGs cut out against the page background.
            imagealphablending($decoded,false);imagesavealpha($decoded,true);
            if(!imagepng($decoded,$stage,6))throw new RuntimeException('Could not encode image.');
            chmod($stage,0600);
            $name=bin2hex(random_bytes(16)).'.png';$relative=$folder.'/'.$name;
            $directory=dirname(__DIR__,2).'/'.$folder;
            if(is_link($directory)||(!is_dir($directory)&&!mkdir($directory,0755,true)))throw new RuntimeException('Image storage is unavailable.');
            $public=$directory.'/'.$name;
            if(!copy($stage,$public)||!chmod($public,0644))throw new RuntimeException('Image storage is unavailable.');
            return ['path'=>$relative,'width'=>$dimensions[0],'height'=>$dimensions[1]];
        }catch(Throwable $e){if($public&&is_file($public))unlink($public);throw $e;}
        finally{imagedestroy($decoded);if(is_file($stage))unlink($stage);}
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
