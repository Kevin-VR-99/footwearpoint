<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Model
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'usuarios';

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'telefono',
        'estado',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function aceptacionesLegales()
    {
        return $this->hasMany(AceptacionLegal::class, 'usuario_id');
    }

    public function membresiasStaff()
    {
        return $this->hasMany(DistribuidoraStaff::class, 'usuario_id');
    }

    public function revendedor()
    {
        return $this->hasOne(Revendedor::class, 'usuario_id');
    }

    public function clientesDirectos()
    {
        return $this->hasMany(ClienteDirecto::class, 'usuario_id');
    }

    public function notificaciones()
    {
        return $this->hasMany(Notificacion::class, 'usuario_id');
    }

    public function dispositivosFcm()
    {
        return $this->hasMany(DispositivoFcm::class, 'usuario_id');
    }

    public function auditorias()
    {
        return $this->hasMany(Auditoria::class, 'usuario_id');
    }
}