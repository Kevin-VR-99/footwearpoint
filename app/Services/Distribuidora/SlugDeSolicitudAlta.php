<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Services\Tienda\DominioTienda;
use Illuminate\Support\Str;

/**
 * TG-220 (E2-08) — El slug de una distribuidora que pide su alta sola.
 *
 * El formulario público no pide slug ni subdominio: son palabras técnicas
 * para quien apenas se está dando de alta. Se saca del nombre comercial, y
 * tiene que servir como subdominio, porque CrearDistribuidoraAction usa el
 * slug como subdominio cuando no le dan otro.
 *
 * Por eso cumple las reglas de TG-232 (DominioTienda::FORMATO, no reservado)
 * y no choca con ninguna otra distribuidora, ni por slug ni por subdominio.
 * Si choca, se le agrega un sufijo numérico. El administrador lo puede
 * cambiar después desde su perfil.
 */
class SlugDeSolicitudAlta
{
    /** Hasta dónde se prueba con sufijo antes de rendirse y usar uno al azar. */
    private const MAX_INTENTOS = 50;

    public function paraNombre(string $nombreComercial): string
    {
        $base = $this->base($nombreComercial);

        if ($this->estaLibre($base)) {
            return $base;
        }

        for ($i = 2; $i <= self::MAX_INTENTOS; $i++) {
            $candidato = $this->recortar($base, strlen((string) $i) + 1).'-'.$i;

            if ($this->estaLibre($candidato)) {
                return $candidato;
            }
        }

        // Rarísimo, pero antes de fallar se usa algo que seguro está libre.
        return $this->recortar($base, 7).'-'.Str::lower(Str::random(6));
    }

    /**
     * Un nombre DNS válido sacado del nombre comercial. Si no queda nada
     * usable (un nombre con puros símbolos, o demasiado corto), se parte de
     * "distribuidora".
     */
    private function base(string $nombreComercial): string
    {
        $slug = trim(Str::slug($nombreComercial), '-');
        $slug = $this->recortar($slug, 0);

        if (strlen($slug) < 3 || preg_match(DominioTienda::FORMATO, $slug) !== 1) {
            return 'distribuidora';
        }

        return $slug;
    }

    /** Deja sitio para el sufijo sin pasarse de los 63 caracteres del DNS. */
    private function recortar(string $slug, int $espacioParaSufijo): string
    {
        return rtrim(substr($slug, 0, max(3, 63 - $espacioParaSufijo)), '-');
    }

    private function estaLibre(string $slug): bool
    {
        if (DominioTienda::esReservado($slug)) {
            return false;
        }

        // El slug también se usa como subdominio, así que no puede chocar con
        // ninguno de los dos de otra distribuidora.
        return ! Distribuidora::query()
            ->where('slug', $slug)
            ->orWhere('subdominio', $slug)
            ->exists();
    }
}
