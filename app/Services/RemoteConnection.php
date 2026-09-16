<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The ERP database of a company: the named connection it is linked to
 * (config/omc.php) or a connection built from the credentials stored on it.
 */
class RemoteConnection
{
    public function connection(Company $company): ConnectionInterface
    {
        $name = $this->name($company);

        if ($company->erpConnection() === null) {
            Config::set("database.connections.{$name}", $company->remoteConnectionConfig());
            DB::purge($name);
        }

        return DB::connection($name);
    }

    public function name(Company $company): string
    {
        return $company->erpConnection() ?? "company_{$company->getKey()}";
    }
}
