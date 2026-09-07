<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    protected $table = 'credit_transactions';

    protected $fillable = [
        'user_id','empresa_id','module','change','balance_after','type','credit_package_id','payment_transaction_id','description'
    ];

    protected $casts = [
        'change' => 'integer',
        'balance_after' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
