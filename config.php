<?php
declare(strict_types=1);

return [
    'app_name' => 'AluPanel CRM',
    'brand'    => 'AluPanel',
    'company'  => 'AMI',
    'tagline'  => 'Sales Platform',
    'currency' => 'Rp',
    'ppn_rate' => 11,           // effective PPN % of subtotal (12% VAT on DPP = subtotal×11/12)

    // ── Company letterhead (printed invoices / delivery orders) ──
    'company_full'  => 'PT ALUPANEL MULIA INDONESIA',
    'company_addr'  => 'JL Pinangsia Raya no 83, kecamatan taman sari, kelurahan pinangsia, kode pos 11110',
    'company_phone' => '',
    'company_logo'  => 'ALUSIGNPANEL',   // text logo; or drop a file at public/assets/img/logo.png
    'signer_name'   => 'MUTIARA FARIANDA',
    'signer_title'  => 'Finance Manager',
    'banks' => [
        [
            'name' => 'Bank ICBC',
            'branch' => 'PT. Industrial and Commercial Bank Of China (Indonesia)',
            'account_name' => 'PT ALUPANEL MULIA INDONESIA',
            'account_no' => '0120010300000397082',
            'swift' => 'ICBKIDJA',
        ],
        [
            'name' => 'Bank BCA',
            'branch' => 'BCA JIEXPO KEMAYORAN',
            'account_name' => 'ALUPANEL MULIA INDONESIA PT',
            'account_no' => '7530306865',
            'swift' => 'CENAIDJA',
        ],
    ],

    'db_path'  => __DIR__ . '/data/crm.sqlite',
];
