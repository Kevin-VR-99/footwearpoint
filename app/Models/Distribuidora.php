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

    public function staff()
    {
        return $this->hasMany(DistribuidoraStaff::class, 'distribuidora_id');
    }

    public function revendedoresAfiliados()
    {
        return $this->hasMany(RevendedorDistribuidora::class, 'distribuidora_id');
    }

    public function clientesDirectos()
    {
        return $this->hasMany(ClienteDirecto::class, 'distribuidora_id');
    }

    public function marcas()
    {
        return $this->hasMany(Marca::class, 'distribuidora_id');
    }

    public function categoriasProducto()
    {
        return $this->hasMany(CategoriaProducto::class, 'distribuidora_id');
    }

    public function campanas()
    {
        return $this->hasMany(Campana::class, 'distribuidora_id');
    }

    public function productos()
    {
        return $this->hasMany(Producto::class, 'distribuidora_id');
    }

    public function ciclosCompra()
    {
        return $this->hasMany(CicloCompra::class, 'distribuidora_id');
    }

    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'distribuidora_id');
    }

    public function ventasDirectas()
    {
        return $this->hasMany(VentaDirecta::class, 'distribuidora_id');
    }

    public function vales()
    {
        return $this->hasMany(Vale::class, 'distribuidora_id');
    }

    public function importacionesCatalogo()
    {
        return $this->hasMany(ImportacionCatalogo::class, 'distribuidora_id');
    }

    public function productosDestacados()
    {
        return $this->hasMany(ProductoDestacado::class, 'distribuidora_id');
    }
}