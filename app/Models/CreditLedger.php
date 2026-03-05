<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditLedger extends Model
{
    public $timestamps = false; // we used created_at only
    protected $table = 'credit_ledger';
    protected $fillable = ['customer_id','type','credits_delta','balance_after','related_type','related_id','meta','created_at'];
    protected $casts = ['meta'=>'array','created_at'=>'datetime'];
}
