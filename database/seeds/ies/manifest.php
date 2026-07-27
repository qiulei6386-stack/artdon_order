<?php

declare(strict_types=1);

/*
 * These profiles are synthetic, preliminary workflow fixtures. They are not
 * manufacturer-supplied or laboratory-validated photometry.
 */
return [
    [
        'public_id' => 'IES-DEMO-AL1010-36D',
        'product_sku' => 'AL1010',
        'configured_model' => 'AL1010-20W-3000K-36D-DEMO',
        'filename' => 'preliminary-demo-al1010-20w-36d.ies',
        'version' => 1,
        'match_options' => [
            'beam' => '36',
            'power' => '20W',
        ],
    ],
    [
        'public_id' => 'IES-DEMO-AT2020-24D',
        'product_sku' => 'AT2020',
        'configured_model' => 'AT2020-20W-3000K-24D-DEMO',
        'filename' => 'preliminary-demo-at2020-20w-24d.ies',
        'version' => 1,
        'match_options' => [
            'beam' => '24',
            'power' => '20W',
        ],
    ],
    [
        'public_id' => 'IES-DEMO-LN4010-60D',
        'product_sku' => 'LN4010',
        'configured_model' => 'LN4010-40W-4000K-60D-DEMO',
        'filename' => 'preliminary-demo-ln4010-40w-60d.ies',
        'version' => 1,
        'match_options' => [
            'beam' => '60',
            'power' => '40W',
        ],
    ],
];
