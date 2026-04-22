<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class RemoteConnection
{
    public function connection(Company $company): ConnectionInterface
    {
        $name = $this->name($company);

        Config::set("database.connections.{$name}", $company->remoteConnectionConfig());
        DB::purge($name);

        return DB::connection($name);
    }

    public function name(Company $company): string
    {
        return "company_{$company->getKey()}";
    }
}
