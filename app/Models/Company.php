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
