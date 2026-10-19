<?php

/** Store-visit enquiry capture. Guests may submit; staff read it through the admin console. */
final class EnquiryService
{
    public function __construct(private PDO $pdo) {}

    public function submit(array $input): void
    {
        $name=CommerceValues::text($input['name']??'',100,'name');
        // Same contact normalisation as delivery addresses: strip separators, then a bounded phone shape.
        $contact=preg_replace('/[ ()-]/','',CommerceValues::text($input['contact']??'',20,'contact'));
        if(!preg_match('/\A\+?[0-9]{7,15}\z/',$contact)) CommerceValues::invalid('contact');
        $note=CommerceValues::text($input['note']??'',500,'note',true);
        $productId=isset($input['product_id'])&&$input['product_id']!==''?CommerceValues::id($input['product_id'],'product_id'):null;
        if($productId!==null && !CommerceDatabase::query($this->pdo,"SELECT 1 FROM products WHERE id=? AND status='active'",[$productId])->fetchColumn())
            CommerceValues::invalid('product_id');
        CommerceDatabase::query($this->pdo,'INSERT INTO store_visit_enquiries (product_id,customer_name,contact,note,created_at) VALUES (?,?,?,?,?)',
            [$productId,$name,$contact,$note,time()]);
    }
}
