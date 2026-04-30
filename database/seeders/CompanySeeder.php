<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;
use RuntimeException;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $path = storage_path('app/private/companies.json');

        if (! is_file($path)) {
            throw new RuntimeException(
                "Companies seed file not found at {$path}. Create it on the server before seeding."
            );
        }

        $companies = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($companies as $company) {
            Company::updateOrCreate(
                ['name' => $company['name']],
                [
                    'cui' => $company['cui'],
                    'db_driver' => $company['db_driver'],
                    'db_host' => $company['db_host'],
                    'db_port' => $company['db_port'],
                    'db_database' => $company['db_database'],
                    'db_username' => $company['db_username'],
                    'db_password' => $company['db_password'],
                ]
            );
        }
    }
}
