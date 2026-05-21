<?php

/**
 * Master data snapshot for asset groups & assets (company block B).
 * Edit COMPANY_B_ID in AssetMasterDataSeeder when deploying to another server.
 */
return [
    'groups' => [
        [
            'name' => 'Perelatan Kantor',
            'description' => null,
            'useful_life_months' => 48,
            'default_method' => 'garis_lurus',
            'asset_account_name' => 'Biaya Penyusutan Peralatan',
            'contra_account_name' => 'Akumulasi Penyusutan Peralatan',
        ],
    ],
    'assets' => [
        [
            'group_name' => 'Perelatan Kantor',
            'asset_code' => null,
            'name' => 'Lap top',
            'acquisition_date' => '2022-08-01',
            'acquisition_cost' => 1944900000,
            'useful_life_months' => 45,
            'salvage_value' => 0,
            'depreciation_method' => 'garis_lurus',
            'status' => 'fully_depreciated',
            'meta' => null,
        ],
    ],
];
