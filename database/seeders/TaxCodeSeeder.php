<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaxCodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('tax_codes')->insert([
            ['code' => '01', 'description' => 'Kepada selain Pemungut PPN'],
            ['code' => '02', 'description' => 'Kepada Pemungut PPN Instansi Pemerintah'],
            ['code' => '03', 'description' => 'Kepada Pemungut PPN selain Instansi Pemerintah'],
            ['code' => '04', 'description' => 'DPP Nilai Lain'],
            ['code' => '05', 'description' => 'Besaran Tertentu'],
            ['code' => '06', 'description' => 'Kepada orang pribadi pemegang paspor luar negeri (Pasal 16E UU PPN)'],
            ['code' => '07', 'description' => 'Penyerahan dengan fasilitas PPN atau PPN dan PPnBM tidak dipungut/ditanggung pemerintah'],
            ['code' => '08', 'description' => 'Penyerahan dengan fasilitas dibebaskan PPN atau PPN dan PPnBM'],
            ['code' => '09', 'description' => 'Penyerahan aktiva yang menurut tujuan semula tidak untuk diperjualbelikan (Pasal 16D UU PPN)'],
            ['code' => '10', 'description' => 'Penyerahan lainnya'],
        ]);
    }
}
