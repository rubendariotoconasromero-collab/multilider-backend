<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nombre',
        'correo',
        'password',
        'estado'
    ];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected static function booted(): void
    {
        static::updated(function (self $user) {
            if ($user->wasChanged('password') || $user->wasChanged('estado')) {
                $user->tokens()->delete();
            }
        });
    }

    public function rolesPermisos() {
        return $this->hasMany(RolPermisoUsuario::class, 'user_id');
    }

    public function asignaciones()
    {

        return $this->belongsToMany(
            RolPermiso::class, 
            'rol_permiso_usuario',
            'user_id', 
            'rol_permiso_id'
        );
    }

    /**
     * Verifica si el usuario tiene un permiso específico.
     */
    public function hasPermission($permissionName)
    {
        if (!$this->estado) { return false; }

        return $this->asignaciones()->whereHas('rol', fn($q) => $q->where('estado', true))->whereHas('permiso', function ($query) use ($permissionName) {
            $query->where('nombre', $permissionName)->where('estado', true);
        })->exists();
    }
}
