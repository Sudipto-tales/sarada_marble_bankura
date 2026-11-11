<?php

final class AddressService
{
    public function __construct(private PDO $pdo) {}
    private function query(string $sql,array $args=[]):PDOStatement {return CommerceDatabase::query($this->pdo,$sql,$args);}
    public function listing(int $userId):array
    {
        CommerceAccess::requireActor($userId);
        return $this->query('SELECT id,user_id,label,recipient,phone,line1,line2,city,state,postal_code,country_code,is_default,revision FROM addresses WHERE user_id=? AND enabled=1 ORDER BY is_default DESC,id',[$userId])->fetchAll();
    }
    public function get(int $userId,int $id):array
    {
        CommerceAccess::requireActor($userId);
        return CommerceAccess::requireOwned($this->query('SELECT * FROM addresses WHERE id=? AND user_id=? AND enabled=1',[$id,$userId])->fetch(),$userId);
    }
    public function save(int $userId,array $input,int $expectedRevision=0):array
    {
        CommerceAccess::requireActor($userId);$id=isset($input['id'])?CommerceValues::id($input['id']):null;
        $data=[];foreach(['label'=>40,'recipient'=>190,'line1'=>190,'line2'=>190,'city'=>100,'state'=>100] as $field=>$max) $data[$field]=CommerceValues::text($input[$field]??($field==='label'?'Home':''),$max,$field,$field==='line2');
        $phone=preg_replace('/[ ()-]/','',CommerceValues::text($input['phone']??'',20,'phone'));
        if(!preg_match('/\A\+?[0-9]{7,15}\z/',$phone)) CommerceValues::invalid('phone');$data['phone']=$phone;
        $data['country_code']=strtoupper(CommerceValues::text($input['country_code']??'IN',2,'country_code'));
        // Stage 1 delivery is India; expansion requires an explicit country/address policy.
        if($data['country_code']!=='IN') CommerceValues::invalid('country_code');
        $data['postal_code']=CommerceValues::text($input['postal_code']??'',16,'postal_code');
        if(!preg_match('/\A[1-9][0-9]{5}\z/',$data['postal_code'])) CommerceValues::invalid('postal_code');
        $default=CommerceValues::flag($input['is_default']??0,'is_default');CommerceValues::integer($expectedRevision,0,PHP_INT_MAX,'revision');
        $saved=CommerceDatabase::transaction($this->pdo,function() use($userId,$id,$data,$default,$expectedRevision):int {
            $this->query('SELECT id FROM users_tbl WHERE id=?'.CommerceDatabase::lock($this->pdo),[$userId])->fetch();CommerceAccess::requireActor($userId);
            $rows=$this->query('SELECT * FROM addresses WHERE user_id=? AND enabled=1 ORDER BY id'.CommerceDatabase::lock($this->pdo),[$userId])->fetchAll();
            $old=null;foreach($rows as $row) if((int)$row['id']===$id) $old=$row;
            if($id!==null&&!$old) throw new RequestRejected(404,'not_found','Address not found.');
            if(($old?(int)$old['revision']:0)!==$expectedRevision) throw new RequestRejected(409,'revision_conflict','Address changed. Refresh and retry.');
            if(!$old&&count($rows)>=20) throw new RequestRejected(422,'address_limit','Up to 20 delivery addresses are supported.');
            $isDefault=$default||!$rows||($old&&(int)$old['is_default']===1)?1:0;
            if($isDefault) $this->query('UPDATE addresses SET is_default=0,revision=revision+1 WHERE user_id=? AND enabled=1 AND is_default=1'.($id?' AND id<>?':''),$id?[$userId,$id]:[$userId]);
            $fields=array_keys($data);$values=array_values($data);
            if($old) {
                $sets=implode(',',array_map(fn($field)=>$field.'=?',$fields));
                $this->query("UPDATE addresses SET $sets,is_default=?,revision=revision+1 WHERE id=? AND user_id=?",[...$values,$isDefault,$id,$userId]);return $id;
            }
            $names=implode(',',$fields);$marks=implode(',',array_fill(0,count($values),'?'));
            $this->query("INSERT INTO addresses (user_id,$names,is_default) VALUES (?,$marks,?)",[$userId,...$values,$isDefault]);return (int)$this->pdo->lastInsertId();
        });return $this->get($userId,$saved);
    }
    public function archive(int $userId,int $id,int $expectedRevision):void
    {
        CommerceDatabase::transaction($this->pdo,function() use($userId,$id,$expectedRevision):void {
            CommerceAccess::requireActor($userId);$this->query('SELECT id FROM users_tbl WHERE id=?'.CommerceDatabase::lock($this->pdo),[$userId])->fetch();
            $rows=$this->query('SELECT * FROM addresses WHERE user_id=? AND enabled=1 ORDER BY id'.CommerceDatabase::lock($this->pdo),[$userId])->fetchAll();
            $old=null;foreach($rows as $row) if((int)$row['id']===$id) $old=$row;
            if(!$old) throw new RequestRejected(404,'not_found','Address not found.');
            if((int)$old['revision']!==$expectedRevision) throw new RequestRejected(409,'revision_conflict','Address changed.');
            $this->query('UPDATE addresses SET enabled=0,is_default=0,revision=revision+1 WHERE id=? AND user_id=?',[$id,$userId]);
            if((int)$old['is_default']===1) foreach($rows as $row) if((int)$row['id']!==$id) {$this->query('UPDATE addresses SET is_default=1,revision=revision+1 WHERE id=?',[$row['id']]);break;}
        });
    }
}
