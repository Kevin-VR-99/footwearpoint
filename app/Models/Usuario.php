<?php

namespace App\Models;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;

class Usuario extends Authenticatable implements CanResetPassword
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, CanResetPasswordTrait;

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

    /**
     * El registro de contacto de esta cuenta, si es de un revendedor o de un
     * cliente directo (TG-216).
     *
     * Ahí viven su nombre y su teléfono: la cuenta solo guarda el acceso.
     *
     * Un cliente directo que le compra a dos distribuidoras tiene un registro
     * en cada una. Se devuelve el de la distribuidora con la que entró, que es
     * la misma cuyo catálogo está viendo en la app.
     */
    public function contacto(): Revendedor|ClienteDirecto|null
    {
        $revendedor = Revendedor::withoutGlobalScopes()->where('usuario_id', $this->id)->first();

        if ($revendedor) {
            return $revendedor;
        }

        return ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $this->id)
            ->when(Tenant::id() !== null, fn ($consulta) => $consulta->where('distribuidora_id', Tenant::id()))
            ->orderBy('distribuidora_id')
            ->first();
    }

    /**
     * El nombre que se muestra en toda la aplicación (TG-216).
     *
     * El del contacto si es revendedor o cliente; el de la cuenta si es
     * personal. Un solo lugar: lo usan el login, el perfil, las
     * notificaciones, la auditoría, los correos y las pantallas.
     */
    public function nombreVisible(): ?string
    {
        return $this->contacto()?->nombre ?? $this->nombre;
    }

    /** Mismo criterio que el nombre, para el teléfono. */
    public function telefonoVisible(): ?string
    {
        return $this->contacto()?->telefono ?? $this->telefono;
    }

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