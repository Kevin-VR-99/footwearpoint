<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Distribuidora extends Model
{
    protected $table = 'distribuidoras';

    protected $fillable = [
        'nombre_comercial',
        'razon_social',
        'rfc',
        'slug',
        'subdominio',
        'logotipo_url',
        'descripcion_publica',
        'direccion_publica',
        'telefono_publico',
        'email_publico',
        'horario_publico',
        'marketplace_visible',
        'estado',
        'fecha_solicitud',
        'fecha_aprobacion',
    ];

    protected $casts = [
        'marketplace_visible' => 'boolean',
        'fecha_solicitud' => 'datetime',
        'fecha_aprobacion' => 'datetime',
    ];

    public function suscripciones()
    {
        return $this->hasMany(Suscripcion::class, 'distribuidora_id');
    }

    public function configuracion()
    {
        return $this->hasOne(ConfiguracionDistribuidora::class, 'distribuidora_id');
    }

    public function configuracionesCiclo()
    {
        return $this->hasMany(ConfiguracionCiclo::class, 'distribuidora_id');
    }

    public function sucursales()
    {
        return $this->hasMany(Sucursal::class, 'distribuidora_id');
    }

    public function sucursalPrincipal()
    {
        return $this->hasOne(Sucursal::class, 'distribuidora_id')->where('es_principal', true);
    }

    // Más relaciones (staff, revendedores, clientes, marcas, pedidos, etc.)
    // se agregan en bloques posteriores, cuando ya existan esos modelos.
}