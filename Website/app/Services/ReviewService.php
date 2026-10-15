<?php

/** One moderated review per customer/product, available only after delivered purchase. */
final class ReviewService
{
    public function __construct(private PDO $pdo) {}
    private function purchase(int $userId,int $productId): bool
    {
        return (bool)CommerceDatabase::query($this->pdo,"SELECT o.id FROM orders o JOIN order_items i ON i.order_id=o.id WHERE o.user_id=? AND i.product_id=? AND o.status='delivered' LIMIT 1",[$userId,$productId])->fetchColumn();
    }
    public function own(int $userId,int $productId): ?array
    {
        CommerceAccess::requireActor($userId);
        return CommerceDatabase::query($this->pdo,'SELECT * FROM product_reviews WHERE user_id=? AND product_id=?',[$userId,$productId])->fetch()?:null;
    }
    public function eligible(int $userId,int $productId): bool { CommerceAccess::requireActor($userId);return $this->purchase($userId,$productId); }
    public function submit(int $userId,int $productId,array $input,int $revision): void
    {
        CommerceAccess::requireActor($userId);$productId=CommerceValues::id($productId);$revision=CommerceValues::integer($revision,0,PHP_INT_MAX,'revision');
        $rating=CommerceValues::integer($input['rating']??null,1,5,'rating');
        $title=CommerceValues::text($input['title']??'',120,'title');$body=CommerceValues::text($input['body']??'',2000,'body');
        if(strlen($body)<10)CommerceValues::invalid('body');
        CommerceDatabase::transaction($this->pdo,function()use($userId,$productId,$rating,$title,$body,$revision):void {
            CommerceDatabase::query($this->pdo,'SELECT id FROM products WHERE id=?'.CommerceDatabase::lock($this->pdo),[$productId])->fetch();
            CommerceAccess::requireActor($userId);(new ProductService($this->pdo))->selection([$productId])?:throw new RequestRejected(404,'not_found','Product not found.');
            if(!$this->purchase($userId,$productId))throw new RequestRejected(403,'purchase_required','You can review this material after your order has been delivered.');
            $old=$this->own($userId,$productId);
            if($revision!==(int)($old['revision']??0))throw new RequestRejected(409,'revision_conflict','Your review changed. Reload before editing.');
            $now=CommerceDatabase::clock($this->pdo);
            if($old)CommerceDatabase::query($this->pdo,"UPDATE product_reviews SET rating=?,title=?,body=?,status='pending',revision=revision+1,updated_at=? WHERE id=?",[$rating,$title,$body,$now,$old['id']]);
            else CommerceDatabase::query($this->pdo,'INSERT INTO product_reviews (product_id,user_id,rating,title,body,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',[$productId,$userId,$rating,$title,$body,$now,$now]);
            ProductService::advanceVersion($this->pdo);
        });
    }
    public function publicPage(int $productId,int $page=1): array
    {
        $page=CommerceValues::integer($page,1,1000,'page');$offset=($page-1)*10;
        $where=" FROM product_reviews r JOIN users_tbl u ON u.id=r.user_id WHERE r.product_id=? AND r.status='approved' AND u.status=1 AND u.email_verify=1";
        $rows=CommerceDatabase::query($this->pdo,'SELECT r.rating,COUNT(*) AS count'.$where.' GROUP BY r.rating',[$productId])->fetchAll();
        $breakdown=array_fill(1,5,0);$total=0;$sum=0;
        foreach($rows as $row){$breakdown[(int)$row['rating']]=(int)$row['count'];$total+=(int)$row['count'];$sum+=(int)$row['rating']*(int)$row['count'];}
        $items=CommerceDatabase::query($this->pdo,'SELECT r.rating,r.title,r.body,r.created_at,u.firstname AS author'.$where." ORDER BY r.id DESC LIMIT 10 OFFSET $offset",[$productId])->fetchAll();
        return ['total'=>$total,'rating_tenths'=>$total?CommerceValues::round($sum*10,$total):null,'breakdown'=>$breakdown,'items'=>$items,'page'=>$page,'pages'=>(int)ceil($total/10)];
    }
    public function pending(int $actorId,int $page=1): array
    {
        CommerceAccess::requirePermission('admin.products.view',$actorId);$page=CommerceValues::integer($page,1,1000,'page');$offset=($page-1)*20;
        return CommerceDatabase::query($this->pdo,"SELECT r.*,p.name AS product_name FROM product_reviews r JOIN products p ON p.id=r.product_id WHERE r.status='pending' ORDER BY r.id LIMIT 20 OFFSET $offset")->fetchAll();
    }
    public function moderate(int $actorId,int $id,string $status,int $revision,string $key): void
    {
        CommerceAccess::requirePermission('admin.products.edit',$actorId);$id=CommerceValues::id($id);$key=CommerceValues::key($key);
        if(!in_array($status,['approved','rejected'],true))CommerceValues::invalid('status');
        CommerceDatabase::transaction($this->pdo,function()use($actorId,$id,$status,$revision,$key):void {
            CommerceAccess::requirePermission('admin.products.edit',$actorId);
            $row=CommerceDatabase::query($this->pdo,'SELECT * FROM product_reviews WHERE id=?'.CommerceDatabase::lock($this->pdo),[$id])->fetch();
            if(!$row)throw new RequestRejected(404,'not_found','Review not found.');
            if((int)$row['revision']!==$revision)throw new RequestRejected(409,'revision_conflict','Review changed. Reload before moderating.');
            if($status==='approved'&&!$this->purchase((int)$row['user_id'],(int)$row['product_id']))throw new RequestRejected(409,'purchase_required','Delivered purchase cannot be verified.');
            CommerceDatabase::query($this->pdo,'UPDATE product_reviews SET status=?,revision=revision+1,updated_at=? WHERE id=?',[$status,CommerceDatabase::clock($this->pdo),$id]);
            ProductService::advanceVersion($this->pdo);
            StaffAudit::record($this->pdo,$actorId,'review.moderate','review',$id,$key,['status'=>$status,'revision'=>$revision+1]);
        });
    }
}
