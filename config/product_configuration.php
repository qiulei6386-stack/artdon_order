<?php

declare(strict_types=1);

$trackSpotOptions = [
    ['code' => 'fixture', 'label' => 'Fixture Type', 'values' => [
        ['code' => 'Housing', 'label' => 'Housing', 'sku' => 'H'],
        ['code' => 'Complete', 'label' => 'Complete', 'sku' => 'C', 'default' => true],
    ]],
    ['code' => 'power', 'label' => 'Power', 'values' => [
        ['code' => '12W', 'label' => '12W', 'sku' => '12W', 'price_delta' => 0],
        ['code' => '18W', 'label' => '18W', 'sku' => '18W', 'price_delta' => 8],
        ['code' => '20W', 'label' => '20W', 'sku' => '20W', 'price_delta' => 12, 'default' => true],
        ['code' => '25W', 'label' => '25W', 'sku' => '25W', 'price_delta' => 18],
    ]],
    ['code' => 'light_source', 'label' => 'Light Source', 'values' => [
        ['code' => 'Bridgelux', 'label' => 'Bridgelux', 'sku' => 'BX', 'default' => true],
        ['code' => 'Cree', 'label' => 'Cree', 'sku' => 'CR', 'price_delta' => 4],
    ]],
    ['code' => 'driver', 'label' => 'Driver', 'values' => [
        ['code' => 'Lifud', 'label' => 'Lifud', 'sku' => 'LIF', 'default' => true],
        ['code' => 'Tridonic', 'label' => 'Tridonic', 'sku' => 'TRI', 'price_delta' => 8],
        ['code' => 'Eaglerise', 'label' => 'Eaglerise', 'sku' => 'EAG', 'price_delta' => 3],
    ]],
    ['code' => 'control', 'label' => 'Control', 'values' => [
        ['code' => 'On/Off', 'label' => 'On/Off', 'sku' => 'ON', 'default' => true],
        ['code' => 'DALI-2', 'label' => 'DALI-2', 'sku' => 'DALI', 'price_delta' => 12],
    ]],
    ['code' => 'cri', 'label' => 'CRI', 'values' => [
        ['code' => 'CRI90', 'label' => 'CRI90', 'sku' => '90', 'default' => true],
        ['code' => 'CRI95', 'label' => 'CRI95', 'sku' => '95', 'price_delta' => 4],
    ]],
    ['code' => 'beam', 'label' => 'Beam Angle', 'values' => [
        ['code' => '15', 'label' => '15°', 'sku' => '15D'],
        ['code' => '24', 'label' => '24°', 'sku' => '24D', 'default' => true],
        ['code' => '36', 'label' => '36°', 'sku' => '36D'],
        ['code' => '60', 'label' => '60°', 'sku' => '60D'],
    ]],
    ['code' => 'color', 'label' => 'Color', 'values' => [
        ['code' => 'Black', 'label' => 'Black', 'sku' => 'BK', 'default' => true],
        ['code' => 'White', 'label' => 'White', 'sku' => 'WH'],
    ]],
    ['code' => 'cct', 'label' => 'CCT', 'values' => [
        ['code' => '3000K', 'label' => '3000K', 'sku' => '3000K', 'default' => true],
        ['code' => '4000K', 'label' => '4000K', 'sku' => '4000K'],
        ['code' => '5000K', 'label' => '5000K', 'sku' => '5000K'],
    ]],
];

return [
    'default' => [
        'price_mode' => 'fixed',
        'sku_order' => ['series', 'power', 'color', 'cct', 'beam', 'driver', 'control'],
        'options' => [
            ['code' => 'power', 'label' => 'Power', 'values' => [
                ['code' => '10W', 'label' => '10W', 'sku' => '10W', 'default' => true],
                ['code' => '15W', 'label' => '15W', 'sku' => '15W', 'price_delta' => 5],
                ['code' => '20W', 'label' => '20W', 'sku' => '20W', 'price_delta' => 11],
            ]],
            ['code' => 'driver', 'label' => 'Driver', 'values' => [
                ['code' => 'Lifud', 'label' => 'Lifud', 'sku' => 'LIF', 'default' => true],
                ['code' => 'Tridonic', 'label' => 'Tridonic', 'sku' => 'TRI', 'price_delta' => 8],
            ]],
            ['code' => 'beam', 'label' => 'Beam Angle', 'values' => [
                ['code' => '24', 'label' => '24°', 'sku' => '24D', 'default' => true],
                ['code' => '36', 'label' => '36°', 'sku' => '36D'],
                ['code' => '60', 'label' => '60°', 'sku' => '60D'],
            ]],
            ['code' => 'color', 'label' => 'Color', 'values' => [
                ['code' => 'White', 'label' => 'White', 'sku' => 'WH', 'default' => true],
                ['code' => 'Black', 'label' => 'Black', 'sku' => 'BK'],
            ]],
            ['code' => 'cct', 'label' => 'CCT', 'values' => [
                ['code' => '3000K', 'label' => '3000K', 'sku' => '3000K', 'default' => true],
                ['code' => '4000K', 'label' => '4000K', 'sku' => '4000K'],
                ['code' => '5000K', 'label' => '5000K', 'sku' => '5000K'],
            ]],
        ],
        'rules' => [
            ['type' => 'deny', 'when' => ['power' => '20W'], 'option' => 'beam', 'value' => '15', 'message' => '20W products cannot use a 15° beam angle.'],
            ['type' => 'deny', 'when' => ['control' => 'DALI-2'], 'option' => 'driver', 'value' => 'Lifud', 'message' => 'DALI-2 requires a compatible driver.'],
        ],
    ],
    'track-lighting' => [
        'price_mode' => 'fixed',
        'sku_order' => ['series', 'power', 'color', 'cct', 'beam', 'driver', 'control'],
        'options' => $trackSpotOptions,
        'rules' => [
            ['type' => 'deny', 'when' => ['power' => '20W'], 'option' => 'beam', 'value' => '15', 'message' => '20W products cannot use a 15° beam angle.'],
            ['type' => 'deny', 'when' => ['control' => 'DALI-2'], 'option' => 'driver', 'value' => 'Lifud', 'message' => 'DALI-2 cannot use Lifud driver.'],
            ['type' => 'deny', 'when' => ['fixture' => 'Housing'], 'option' => 'driver', 'value' => 'Tridonic', 'message' => 'Housing-only supply excludes Tridonic driver.'],
        ],
    ],
    'accessories' => [
        'price_mode' => 'review',
        'sku_order' => ['series', 'compatible_model', 'color'],
        'options' => [
            ['code' => 'compatible_model', 'label' => 'Compatible Model', 'values' => [
                ['code' => 'AL1010', 'label' => 'AL1010', 'sku' => 'AL1010', 'default' => true],
                ['code' => 'AT2020', 'label' => 'AT2020', 'sku' => 'AT2020'],
                ['code' => 'ATL2030', 'label' => 'ATL2030', 'sku' => 'ATL2030'],
            ]],
            ['code' => 'color', 'label' => 'Color', 'values' => [
                ['code' => 'Black', 'label' => 'Black', 'sku' => 'BK', 'default' => true],
                ['code' => 'White', 'label' => 'White', 'sku' => 'WH'],
            ]],
        ],
        'rules' => [
            ['type' => 'deny', 'when' => ['compatible_model' => 'ATL2030'], 'option' => 'color', 'value' => 'White', 'message' => 'This accessory supports ATL2030 in black only.'],
        ],
    ],
    'driver' => [
        'price_mode' => 'fixed',
        'sku_order' => ['series', 'power', 'control'],
        'options' => [
            ['code' => 'power', 'label' => 'Power', 'values' => [
                ['code' => '15W', 'label' => '15W', 'sku' => '15W', 'default' => true],
                ['code' => '25W', 'label' => '25W', 'sku' => '25W', 'price_delta' => 4],
                ['code' => '40W', 'label' => '40W', 'sku' => '40W', 'price_delta' => 9],
            ]],
            ['code' => 'control', 'label' => 'Control', 'values' => [
                ['code' => 'DALI-2', 'label' => 'DALI-2', 'sku' => 'DALI', 'default' => true],
                ['code' => 'Push', 'label' => 'Push', 'sku' => 'PUSH'],
            ]],
        ],
        'rules' => [],
    ],
];
