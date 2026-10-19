<?php

/** One review per customer/product (shown on submit), with likes and one level of replies. */
final class ReviewService
{
    public function __construct(private PDO $pdo) {}
    public function own(int $userId,int $productId): ?array
    {
        CommerceAccess::requireActor($userId);
        return CommerceDatabase::query($this->pdo,'SELECT * FROM product_reviews WHERE user_id=? AND product_id=?',[$userId,$productId])->fetch()?:null;
    }
    /** Any active verified account may review; no delivered-purchase requirement. */
    public function eligible(int $userId,int $productId): bool { CommerceAccess::requireActor($userId);return true; }
    public function submit(int $userId,int $productId,array $input,int $revision): void
    {
        CommerceAccess::requireActor($userId);$productId=CommerceValues::id($productId);$revision=CommerceValues::integer($revision,0,PHP_INT_MAX,'revision');
        $tenths=CommerceValues::decimal((string)($input['rating']??''),1,50,'rating'); // scaled max = 5.0 in tenths
        if($tenths<5||$tenths%5!==0)CommerceValues::invalid('rating'); // 0.5..5.0 in half-star steps
        $whole=max(1,CommerceValues::round($tenths,10)); // 1..5 mirror for the legacy CHECK column
        $title=CommerceValues::text($input['title']??'',120,'title');$body=CommerceValues::text($input['body']??'',2000,'body');
        if(strlen($body)<10)CommerceValues::invalid('body');
        CommerceDatabase::transaction($this->pdo,function()use($userId,$productId,$whole,$tenths,$title,$body,$revision):void {
            CommerceDatabase::query($this->pdo,'SELECT id FROM products WHERE id=?'.CommerceDatabase::lock($this->pdo),[$productId])->fetch();
            CommerceAccess::requireActor($userId);(new ProductService($this->pdo))->selection([$productId])?:throw new RequestRejected(404,'not_found','Product not found.');
            $old=$this->own($userId,$productId);
            if($revision!==(int)($old['revision']??0))throw new RequestRejected(409,'revision_conflict','Your review changed. Reload before editing.');
            $now=CommerceDatabase::clock($this->pdo);
            if($old)CommerceDatabase::query($this->pdo,"UPDATE product_reviews SET rating=?,rating_tenths=?,title=?,body=?,status='approved',revision=revision+1,updated_at=? WHERE id=?",[$whole,$tenths,$title,$body,$now,$old['id']]);
            else CommerceDatabase::query($this->pdo,"INSERT INTO product_reviews (product_id,user_id,rating,rating_tenths,title,body,status,created_at,updated_at) VALUES (?,?,?,?,?,?,'approved',?,?)",[$productId,$userId,$whole,$tenths,$title,$body,$now,$now]);
            ProductService::advanceVersion($this->pdo);
        });
    }
    public function reply(int $userId,int $reviewId,string $body): array
    {
        CommerceAccess::requireActor($userId);$reviewId=CommerceValues::id($reviewId);
        $body=CommerceValues::text($body,2000,'body');if(strlen($body)<2)CommerceValues::invalid('body');
        return CommerceDatabase::transaction($this->pdo,function()use($userId,$reviewId,$body):array {
            CommerceAccess::requireActor($userId);
            if(!CommerceDatabase::query($this->pdo,'SELECT id FROM product_reviews WHERE id=?'.CommerceDatabase::lock($this->pdo),[$reviewId])->fetch())throw new RequestRejected(404,'not_found','Review not found.');
            $now=CommerceDatabase::clock($this->pdo);
            CommerceDatabase::query($this->pdo,'INSERT INTO review_replies (review_id,user_id,body,created_at) VALUES (?,?,?,?)',[$reviewId,$userId,$body,$now]);
            $author=CommerceDatabase::query($this->pdo,'SELECT firstname FROM users_tbl WHERE id=?',[$userId])->fetchColumn();
            return ['id'=>(int)$this->pdo->lastInsertId(),'review_id'=>$reviewId,'body'=>$body,'created_at'=>$now,'author'=>$author,'likes'=>0,'liked'=>false];
        });
    }
    public function react(int $userId,string $targetType,int $targetId): array
    {
        CommerceAccess::requireActor($userId);
        if(!in_array($targetType,['review','reply'],true))CommerceValues::invalid('target');$targetId=CommerceValues::id($targetId);
        return CommerceDatabase::transaction($this->pdo,function()use($userId,$targetType,$targetId):array {
            CommerceAccess::requireActor($userId);
            $table=$targetType==='review'?'product_reviews':'review_replies';
            if(!CommerceDatabase::query($this->pdo,"SELECT id FROM $table WHERE id=?".CommerceDatabase::lock($this->pdo),[$targetId])->fetch())throw new RequestRejected(404,'not_found','Not found.');
            $own=CommerceDatabase::query($this->pdo,'SELECT id FROM review_likes WHERE target_type=? AND target_id=? AND user_id=?',[$targetType,$targetId,$userId])->fetchColumn();
            if($own){CommerceDatabase::query($this->pdo,'DELETE FROM review_likes WHERE id=?',[$own]);$liked=false;}
            else {CommerceDatabase::query($this->pdo,'INSERT INTO review_likes (target_type,target_id,user_id,created_at) VALUES (?,?,?,?)',[$targetType,$targetId,$userId,CommerceDatabase::clock($this->pdo)]);$liked=true;}
            return ['liked'=>$liked,'count'=>(int)CommerceDatabase::query($this->pdo,'SELECT COUNT(*) FROM review_likes WHERE target_type=? AND target_id=?',[$targetType,$targetId])->fetchColumn()];
        });
    }
    public function publicPage(int $productId,int $page=1,?int $viewerId=null): array
    {
        $page=CommerceValues::integer($page,1,1000,'page');$offset=($page-1)*10;
        $where=" FROM product_reviews r JOIN users_tbl u ON u.id=r.user_id WHERE r.product_id=? AND r.status='approved' AND u.status=1 AND u.email_verify=1";
        $rows=CommerceDatabase::query($this->pdo,'SELECT r.rating,COUNT(*) AS count'.$where.' GROUP BY r.rating',[$productId])->fetchAll();
        $breakdown=array_fill(1,5,0);$total=0;
        foreach($rows as $row){$breakdown[(int)$row['rating']]=(int)$row['count'];$total+=(int)$row['count'];}
        $sumTenths=(int)CommerceDatabase::query($this->pdo,'SELECT COALESCE(SUM(COALESCE(r.rating_tenths,r.rating*10)),0)'.$where,[$productId])->fetchColumn();
        $items=CommerceDatabase::query($this->pdo,'SELECT r.id,r.rating,COALESCE(r.rating_tenths,r.rating*10) AS rating_tenths,r.title,r.body,r.created_at,r.user_id,u.firstname AS author'.$where." ORDER BY r.id DESC LIMIT 10 OFFSET $offset",[$productId])->fetchAll();
        return ['total'=>$total,'rating_tenths'=>$total?CommerceValues::round($sumTenths,$total):null,'breakdown'=>$breakdown,
            'items'=>$this->decorate($items,$viewerId),'page'=>$page,'pages'=>(int)ceil($total/10)];
    }
    /** Attach replies and like state to a page of reviews in a fixed number of queries. */
    private function decorate(array $items,?int $viewerId): array
    {
        if(!$items)return $items;
        $reviewIds=array_map('intval',array_column($items,'id'));
        $in=implode(',',array_fill(0,count($reviewIds),'?'));
        $replyRows=CommerceDatabase::query($this->pdo,"SELECT rp.id,rp.review_id,rp.body,rp.created_at,rp.user_id,u.firstname AS author FROM review_replies rp JOIN users_tbl u ON u.id=rp.user_id WHERE rp.review_id IN ($in) ORDER BY rp.id",$reviewIds)->fetchAll();
        $replies=[];$replyIds=[];
        foreach($replyRows as $rp){$replyIds[]=(int)$rp['id'];$replies[(int)$rp['review_id']][]=$rp;}
        [$counts,$liked]=$this->likeMaps($reviewIds,$replyIds,$viewerId);
        foreach($items as &$item){
            $rid=(int)$item['id'];
            $item['likes']=$counts['review'][$rid]??0;$item['liked']=$liked['review'][$rid]??false;
            $item['replies']=array_map(static fn(array $rp):array=>$rp+['likes'=>$counts['reply'][(int)$rp['id']]??0,'liked'=>$liked['reply'][(int)$rp['id']]??false],$replies[$rid]??[]);
        }
        return $items;
    }
    private function likeMaps(array $reviewIds,array $replyIds,?int $viewerId): array
    {
        $counts=['review'=>[],'reply'=>[]];$liked=['review'=>[],'reply'=>[]];$clauses=[];$params=[];
        if($reviewIds){$clauses[]="(target_type='review' AND target_id IN (".implode(',',array_fill(0,count($reviewIds),'?'))."))";array_push($params,...$reviewIds);}
        if($replyIds){$clauses[]="(target_type='reply' AND target_id IN (".implode(',',array_fill(0,count($replyIds),'?'))."))";array_push($params,...$replyIds);}
        if(!$clauses)return [$counts,$liked];
        $where=implode(' OR ',$clauses);
        foreach(CommerceDatabase::query($this->pdo,"SELECT target_type,target_id,COUNT(*) AS c FROM review_likes WHERE $where GROUP BY target_type,target_id",$params)->fetchAll() as $row)
            $counts[$row['target_type']][(int)$row['target_id']]=(int)$row['c'];
        if($viewerId)
            foreach(CommerceDatabase::query($this->pdo,"SELECT target_type,target_id FROM review_likes WHERE user_id=? AND ($where)",array_merge([$viewerId],$params))->fetchAll() as $row)
                $liked[$row['target_type']][(int)$row['target_id']]=true;
        return [$counts,$liked];
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
            CommerceDatabase::query($this->pdo,'UPDATE product_reviews SET status=?,revision=revision+1,updated_at=? WHERE id=?',[$status,CommerceDatabase::clock($this->pdo),$id]);
            ProductService::advanceVersion($this->pdo);
            StaffAudit::record($this->pdo,$actorId,'review.moderate','review',$id,$key,['status'=>$status,'revision'=>$revision+1]);
        });
    }
}
