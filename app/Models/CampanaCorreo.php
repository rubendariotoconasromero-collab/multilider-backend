<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampanaCorreo extends Model
{
    protected $table = 'campanas_correo';

    protected $hidden = ['request_key', 'request_hash', 'actor_issuer', 'actor_subject'];

    protected $fillable = [
        'user_id',
        'asunto',
        'mensaje',
        'tipo_destinatario',
        'filtros',
        'total_destinatarios',
        'total_enviados',
        'total_fallidos',
        'estado',
        'origen', 'dataset', 'actor_issuer', 'actor_subject', 'actor_name', 'request_key', 'request_hash', 'total_inciertos',
    ];

    protected $casts = [
        'filtros' => 'array',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function envioDocumental()
    {
        return $this->hasOne(MailDispatch::class, 'campana_id');
    }
}
