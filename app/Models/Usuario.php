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

    // Más relaciones (distribuidora_staff, revendedores, clientes_directos,
    // notificaciones, dispositivos_fcm, auditorias) se agregan en bloques
    // posteriores, cuando ya existan esos modelos.
}