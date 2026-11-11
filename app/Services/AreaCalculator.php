<?php

/** Coverage estimates use bounded integer rational arithmetic, never browser money. */
final class AreaCalculator
{
    private static function ceil(int $n, int $d): int { return intdiv($n, $d) + (int)($n % $d !== 0); }
    public static function estimate(array $input, ?array $variant): array
    {
        $surfaces = $input['surfaces'] ?? [];
        if (!is_array($surfaces) || count($surfaces) < 1 || count($surfaces) > 20) CommerceValues::invalid('surfaces');
        $gross = 0;
        foreach ($surfaces as $surface) {
            if (!is_array($surface)) CommerceValues::invalid('surfaces');
            CommerceValues::text($surface['name'] ?? '', 80, 'surface_name');
            $length = CommerceValues::decimal($surface['length'] ?? '', 3, 1000000, 'length');
            $width = CommerceValues::decimal($surface['width'] ?? '', 3, 1000000, 'width');
            if (!$length || !$width) CommerceValues::invalid('measurements');
            $area = CommerceValues::multiply($length, $width);
            $area = match ($surface['unit'] ?? '') {
                'ft' => self::ceil($area, 1000),
                'm' => self::ceil(CommerceValues::multiply($area, 100000), 9290304),
                'in' => self::ceil($area, 144000),
                default => CommerceValues::invalid('unit'),
            };
            $cutout = CommerceValues::decimal($surface['cutout'] ?? '0', 3, CommerceValues::MAX_QUANTITY, 'cutout');
            if ($cutout >= $area) CommerceValues::invalid('cutout');
            $gross = CommerceValues::sum([$gross, $area - $cutout]);
        }
        $waste = CommerceValues::integer($input['waste_bps'] ?? 1000, 0, 2500, 'wastage');
        $required = self::ceil(CommerceValues::multiply($gross, 10000 + $waste), 10000);
        if ($required > CommerceValues::MAX_QUANTITY) CommerceValues::invalid('area');
        $result = ['net_area_milli'=>$gross,'required_area_milli'=>$required,'qty_milli'=>null,'material_minor'=>null];
        if ($variant !== null) {
            $increment = (int)$variant['qty_increment_milli'];
            if ($variant['sell_unit'] === 'sqft') $quantity = CommerceValues::multiply(self::ceil($required, $increment), $increment);
            elseif (in_array($variant['sell_unit'], ['box','slab'], true) && (int)$variant['coverage_sqft_milli'] > 0) {
                $coverage = CommerceValues::multiply((int)$variant['coverage_sqft_milli'], $increment);
                $quantity = CommerceValues::multiply(self::ceil(CommerceValues::multiply($required, 1000), $coverage), $increment);
            } else throw new RequestRejected(422, 'coverage_missing', 'This variant has no area coverage. Choose a square-foot, slab or box variant with coverage.');
            CommerceValues::quantity($quantity, $variant);
            $result['qty_milli'] = $quantity;
            $result['material_minor'] = CommerceValues::line((int)$variant['unit_price_minor'], $quantity);
        }
        // Optional operator/user estimates stay separate from the checkout policy.
        $polish = CommerceValues::decimal($input['polish_rate'] ?? '0', 2, 1000000, 'polish_rate');
        $install = CommerceValues::decimal($input['install_rate'] ?? '0', 2, 1000000, 'install_rate');
        $result['polish_minor'] = CommerceValues::line($polish, $required);
        $result['install_minor'] = CommerceValues::line($install, $gross);
        $result['estimated_total_minor'] = CommerceValues::sum([$result['material_minor'] ?? 0, $result['polish_minor'], $result['install_minor']]);
        return $result;
    }
}
