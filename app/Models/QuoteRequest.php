<?php

namespace App\Models;

use Database\Factories\QuoteRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteRequest extends Model
{
    /** @use HasFactory<QuoteRequestFactory> */
    use HasFactory;

    protected $guarded = ['id'];
}
