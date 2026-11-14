<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherModel extends Model
{
    protected $table = 'vouchers';

    protected $fillable = [
        'user_id', 'code', 'title', 'merchant', 'description', 'min_spend', 'max_discount',
        'source', 'tier', 'status', 'expires_at', 'used_at',
    ];

    protected $casts = [
        'min_spend' => 'integer',
        'max_discount' => 'integer',
        'tier' => 'integer',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /** Status efektif: kupon tersedia yang lewat masa berlaku dianggap kedaluwarsa. */
    public function effectiveStatus(): string
    {
        if ($this->status === 'digunakan') {
            return 'digunakan';
        }

        return $this->expires_at->isPast() ? 'kedaluwarsa' : 'tersedia';
    }
}
