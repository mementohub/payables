<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        Company::updateOrCreate(
            ['name' => 'Christian Tour'],
            [
                'cui' => '2951972',
                'db_driver' => 'pgsql',
                'db_host' => '127.0.0.1',
                'db_port' => '5432',
                'db_database' => 'christiantour',
                'db_username' => 'root',
                'db_password' => '',
            ]
        );
    }
}
