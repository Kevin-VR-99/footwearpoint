<?php

namespace App\Http\Middleware;

use App\Models\ActividadDiaria;
use App\Services\Actividad\RegistrarActividad;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * TG-406 (A43) — Deja constancia de que esta persona usó la plataforma hoy.
 *
 * El canal sale del grupo de middleware, no de adivinar el navegador: 'app'
 * en las peticiones del API (que es por donde entra la app móvil) y 'panel'
 * en las del sitio. Así no hay que leer el User-Agent, que se puede falsear y
 * además cambia con cada versión de Android.
 *
 * Se marca DESPUÉS de resolver la petición y solo si salió bien: si alguien
 * recibió un 403 o un 500, no uso la plataforma, intentó usarla.
 */
class MarcarActividad
{
    public function __construct(private RegistrarActividad $registro) {}

    public function handle(Request $peticion, Closure $siguiente, string $canal = ActividadDiaria::CANAL_PANEL): Response
    {
        $respuesta = $siguiente($peticion);

        // Sin sesión no hay a quién contar.
        $id = Auth::id();

        if ($id === null) {
            return $respuesta;
        }

        // Solo las que salieron bien. Un 404 o un 403 no son uso.
        if ($respuesta->getStatusCode() >= 400) {
            return $respuesta;
        }

        // Las peticiones internas de Livewire son de la misma visita: no
        // cambian la cuenta (el indice unico las absorbe), pero se saltan
        // para no golpear la base en cada tecla de un buscador en vivo.
        if ($peticion->hasHeader('X-Livewire')) {
            return $respuesta;
        }

        $this->registro->marcar($id, $canal);

        return $respuesta;
    }
}
