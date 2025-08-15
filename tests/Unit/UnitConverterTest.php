<?php

use App\Services\UnitConverter;

beforeEach(function () {
    $this->converter = new UnitConverter();
});

test('converts metric to usa units correctly', function () {
    $result = $this->converter->metricToUsa(4.5, 1.8, 1.6, 1500);
    
    expect($result)->toHaveKeys(['length_in', 'width_in', 'height_in', 'weight_lb']);
    expect($result['length_in'])->toBe(177.17);
    expect($result['width_in'])->toBe(70.87);
    expect($result['height_in'])->toBe(62.99);
    expect($result['weight_lb'])->toBe(3307.0);
});

test('converts usa to metric units correctly', function () {
    $result = $this->converter->usaToMetric(177.17, 70.87, 62.99, 3307);
    
    expect($result)->toHaveKeys(['length_m', 'width_m', 'height_m', 'weight_kg']);
    expect($result['length_m'])->toBe(4.5);
    expect($result['width_m'])->toBe(1.8);
    expect($result['height_m'])->toBe(1.6);
    expect($result['weight_kg'])->toBe(1500.0);
});

test('converts meters to feet and inches correctly', function () {
    $result = $this->converter->metersToFeetInches(1.8);
    
    expect($result)->toHaveKeys(['feet', 'inches', 'display']);
    expect($result['feet'])->toBe(5);
    expect($result['inches'])->toBe(10.0);
    expect($result['display'])->toBe("5' 10\"");
});

test('converts feet and inches to meters correctly', function () {
    $result = $this->converter->feetInchesToMeters(5, 10.0);
    
    expect($result)->toBe(1.78);
});

test('handles zero values correctly', function () {
    $result = $this->converter->metricToUsa(0, 0, 0, 0);
    
    expect($result['length_in'])->toBe(0.0);
    expect($result['width_in'])->toBe(0.0);
    expect($result['height_in'])->toBe(0.0);
    expect($result['weight_lb'])->toBe(0.0);
});

test('rounds values appropriately', function () {
    $result = $this->converter->metricToUsa(1.2345, 1.2345, 1.2345, 1234);
    
    expect($result['length_in'])->toBe(48.6);
    expect($result['width_in'])->toBe(48.6);
    expect($result['height_in'])->toBe(48.6);
    expect($result['weight_lb'])->toBe(2721.0);
});
