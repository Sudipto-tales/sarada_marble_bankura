<?php

final class OrderStates
{
    public const NEXT=['placed'=>'confirmed','confirmed'=>'processing','processing'=>'shipped','shipped'=>'outForDelivery','outForDelivery'=>'delivered'];
    public const CANCELLABLE=['placed','confirmed','processing'];
    public static function validate(string $from,string $to):void
    {
        if((self::NEXT[$from]??null)===$to||($to==='cancelled'&&in_array($from,self::CANCELLABLE,true))) return;
        throw new RequestRejected(409,'invalid_transition','This order status change is not allowed.');
    }
    public static function targets(string $from):array
    {
        $targets=isset(self::NEXT[$from])?[self::NEXT[$from]]:[];
        if(in_array($from,self::CANCELLABLE,true))$targets[]='cancelled';return $targets;
    }
    public static function payment(string $mode):string
    {
        if($mode==='cod')return 'unpaid';
        if($mode==='simulation'&&env('APP_ENV','production')!=='production'&&filter_var(env('CHECKOUT_SIMULATION_ENABLED',false),FILTER_VALIDATE_BOOLEAN))return 'simulated';
        throw new RequestRejected(422,'invalid_payment_mode','Select an available payment method.');
    }
}
