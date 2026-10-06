<?php

require_once __DIR__ . '/RequestSecurity.php';
if (PHP_INT_SIZE !== 8) throw new RuntimeException('Commerce requires a 64-bit PHP runtime.');

final class CommerceValues
{
    public const MAX_MONEY = 1000000000000;
    public const MAX_QUANTITY = 1000000000;

    public static function integer($value, int $min, int $max, string $field): int
    {
        if (!is_int($value) && (!is_string($value) || !preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $value))) self::invalid($field);
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < $min || $number > $max) self::invalid($field);
        return $number;
    }
    public static function id($value, string $field = 'id'): int { return self::integer($value, 1, 2147483647, $field); }
    public static function text($value, int $max, string $field, bool $empty = false): string
    {
        if (!is_string($value)) self::invalid($field);
        $value = trim($value);
        if ((!$empty && $value === '') || strlen($value) > $max || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) self::invalid($field);
        return $value;
    }
    public static function slug($value): string
    {
        $value = self::text($value,120,'slug');
        if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value)) self::invalid('slug');
        return $value;
    }
    public static function key($value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_-]{7,99}\z/', $value)) self::invalid('operation_key');
        return $value;
    }
    public static function flag($value, string $field): int
    {
        if (is_bool($value)) return $value ? 1 : 0;
        return self::integer($value,0,1,$field);
    }
    public static function quantity($value, array $variant): int
    {
        $quantity = self::integer($value,1,self::MAX_QUANTITY,'qty_milli');
        $increment = (int)$variant['qty_increment_milli'];
        if ($increment < 1 || $quantity % $increment !== 0
            || (in_array($variant['sell_unit'],['slab','box'],true) && $quantity % 1000 !== 0)) self::invalid('qty_milli');
        return $quantity;
    }
    public static function multiply(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || ($b !== 0 && $a > intdiv(PHP_INT_MAX,$b))) self::invalid('amount');
        return $a * $b;
    }
    public static function round(int $n, int $d): int
    {
        if ($n < 0 || $d < 1) self::invalid('amount');
        return intdiv($n,$d) + ($n % $d >= intdiv($d,2)+($d%2) ? 1 : 0);
    }
    public static function line(int $price, int $quantity): int { return self::round(self::multiply($price,$quantity),1000); }
    public static function sum(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            if (!is_int($amount) || $amount < 0 || $amount > PHP_INT_MAX-$total) self::invalid('amount');
            $total += $amount;
        }
        return $total;
    }
    public static function decimal($value, int $places, int $max, string $field): int
    {
        if (!is_string($value) || !preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]{1,' . $places . '}))?\z/',trim($value),$parts)) self::invalid($field);
        $whole = self::integer($parts[1],0,$max,$field);
        $scale = 10 ** $places;
        $result = self::sum([self::multiply($whole,$scale),(int)str_pad($parts[2] ?? '',$places,'0')]);
        if ($result > $max) self::invalid($field);
        return $result;
    }
    public static function invalid(string $field): never
    {
        throw new RequestRejected(422,'invalid_input','Invalid ' . $field . '.');
    }
}
