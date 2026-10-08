<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Distribuidora extends Model
{
    protected $table = 'distribuidoras';

    /**
     * Estados en los que la distribuidora no puede operar (TG-195 / TG-196):
     * su personal no entra al panel web (AccesoPanelWebService) y sus
     * revendedores y clientes no entran a la app (AuthController, Tenant).
     * Su información se conserva; nada se borra.
     */
    public const ESTADOS_SIN_OPERACION = ['suspendida', 'rechazada'];

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

    /** TG-196: false si está suspendida o rechazada. */
    public function puedeOperar(): bool
    {
        return ! in_array($this->estado, self::ESTADOS_SIN_OPERACION, true);
    }

    /**
     * TG-196: lo que ve en la app un revendedor o cliente (o cualquier cuenta)
     * de una distribuidora que no puede operar.
     */
    public function mensajeSinOperacionParaApp(): string
    {
        if ($this->estado === 'rechazada') {
            return "{$this->nombre_comercial} no fue aprobada para usar FootwearPoint, así que no puedes usar la app con ella.";
        }

        return "{$this->nombre_comercial} está suspendida por ahora, así que no puedes usar la app con ella. "
            .'Tu cuenta y tu información se conservan; intenta más tarde.';
    }

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

    /**
     * TG-197 (G16) — Categorías del directorio público en las que aparece.
     * Las asigna el admin general; la tabla puente no lleva timestamps.
     */
    public function categoriasDirectorio()
    {
        return $this->belongsToMany(
            CategoriaDirectorio::class,
            'distribuidora_categoria_directorio',
            'distribuidora_id',
            'categoria_directorio_id'
        );
    }
}