<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailDispatch extends Model
{
    protected $table = 'correo_envios';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['destinatarios' => 'array'];
    protected $hidden = ['request_key', 'request_hash', 'actor_issuer', 'actor_subject'];

    public function documents()
    {
        return $this->belongsToMany(MailDocument::class, 'correo_envio_adjuntos', 'envio_id', 'adjunto_id');
    }
}
