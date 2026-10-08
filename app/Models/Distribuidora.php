<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Distribuidora extends Model
{
    protected $table = 'distribuidoras';

    /**
     * TG-195 (G4) — Estados en los que la distribuidora no puede operar: su
     * personal no entra al panel web (ver AccesoPanelWebService).
     */
    public const ESTADOS_SIN_OPERACION = ['rechazada'];

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
        'motivo_rechazo',
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

    /** Las líneas del catálogo que esta distribuidora vende (TG-210). */
    public function lineas()
    {
        return $this->hasMany(DistribuidoraLinea::class, 'distribuidora_id');
    }

    // Las importaciones de catálogo con IA ya no son de una distribuidora:
    // el catálogo es uno solo y lo importa el admin general (TG-208).

    public function productosDestacados()
    {
        return $this->hasMany(ProductoDestacado::class, 'distribuidora_id');
    }
}