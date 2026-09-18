<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rule that sends invoice lines to a department: an OMC cost centre
 * (loc), a supplier (partner), an account prefix or the office the invoice
 * was booked at. A pattern starting with "~" is a case-insensitive regular
 * expression; any other pattern is compared whole, case aside.
 */
class AssignmentRule extends Model
{
    public const KINDS = ['loc', 'partner', 'account', 'office'];

    protected $guarded = [];

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function matches(?string $value): bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        if (str_starts_with($this->pattern, '~')) {
            return @preg_match('/'.str_replace('/', '\/', substr($this->pattern, 1)).'/iu', $value) === 1;
        }

        if ($this->kind === 'account') {
            return str_starts_with($value, $this->pattern);
        }

        return mb_strtolower($value) === mb_strtolower(trim($this->pattern));
    }
}
