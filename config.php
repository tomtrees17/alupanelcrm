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

    // ── WhatsApp notifications ──
    // driver: 'log'    = queue + record only, nothing leaves the server (safe default)
    //         'fonnte' = Fonnte.com HTTP API (Indonesian provider)
    //         'cloud'  = Meta WhatsApp Cloud API
    // Switching provider is a config change; no business code knows the difference.
    'wa' => [
        'driver'   => 'log',
        'token'    => '',      // fonnte: API token | cloud: permanent access token
        'sender'   => '',      // cloud only: phone number ID
        'test_to'  => '',      // if set, EVERY message goes here instead of the real recipient
        'throttle' => 1,       // seconds to wait between sends (avoid provider rate limits)
    ],

    'db_path'  => __DIR__ . '/data/crm.sqlite',
];
