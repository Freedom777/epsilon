<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPending extends Model
{
    protected $fillable = [
        'raw_response',
        'raw_title',
        'normalized_title',
        'source_type',
        'suggested_id',
        'match_score',
        'match_reason',
        'occurrences',
        'status',
        'approved_by',
        'approved_at',
        'admin_comment',
    ];

    protected $casts = [
        'match_score'  => 'decimal:2',
        'occurrences'  => 'integer',
        'approved_at'  => 'datetime',
    ];

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function approve(int $userId, ?string $comment = null): void
    {
        $this->update([
            'status'        => 'approved',
            'approved_by'   => $userId,
            'approved_at'   => now(),
            'admin_comment' => $comment,
        ]);
    }

    public function reject(int $userId, ?string $comment = null): void
    {
        $this->update([
            'status'        => 'rejected',
            'approved_by'   => $userId,
            'approved_at'   => now(),
            'admin_comment' => $comment,
        ]);
    }
}
