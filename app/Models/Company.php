<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = [
        'db_username',
        'db_password',
    ];

    protected function casts(): array
    {
        return [
            'db_username' => 'encrypted',
            'db_password' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class);
    }

    public function etripSuppliers(): HasMany
    {
        return $this->hasMany(EtripSupplier::class);
    }

    /**
     * Name of the eTrip connection in config/database.php, when the company
     * is linked to a reservation database.
     */
    public function etripConnection(): ?string
    {
        $name = $this->etrip_connection;

        return $name && array_key_exists($name, (array) config('etrip.connections')) ? $name : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function remoteConnectionConfig(): array
    {
        return [
            'driver' => $this->db_driver,
            'host' => $this->db_host,
            'port' => $this->db_port,
            'database' => $this->db_database,
            'username' => $this->db_username,
            'password' => $this->db_password,
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }
}
