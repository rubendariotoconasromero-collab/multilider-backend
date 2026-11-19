<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailDocument extends Model
{
    protected $table = 'correo_adjuntos';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['ruta', 'pivot'];
    protected $casts = ['bytes' => 'integer'];
}
