<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Consulta Have I Been Pwned con k-anonimato (solo el prefijo SHA-1).
 * Nunca bloquea un alta o un cambio: si el servicio falla o no hay red,
 * se considera que no hay aviso.
 */
class ConsultarPasswordFiltrada
{
    public const URL = 'https://api.pwnedpasswords.com/range/';

    /**
     * @return bool true si apareció en una filtración; false si no, o si no se pudo consultar.
     */
    public function aparecio(string $password): bool
    {
        $password = (string) $password;
        if ($password === '') {
            return false;
        }

        try {
            $hash = strtoupper(sha1($password));
            $prefijo = substr($hash, 0, 5);
            $sufijo = substr($hash, 5);

            $respuesta = Http::timeout(3)
                ->withHeaders([
                    'Add-Padding' => 'true',
                    'User-Agent' => 'FootwearPoint',
                ])
                ->get(self::URL.$prefijo);

            if (! $respuesta->successful()) {
                return false;
            }

            foreach (preg_split('/\r\n|\n/', (string) $respuesta->body()) as $linea) {
                $linea = trim($linea);
                if ($linea === '') {
                    continue;
                }
                $partes = explode(':', $linea, 2);
                if (strtoupper($partes[0]) === $sufijo) {
                    return ((int) ($partes[1] ?? 0)) > 0;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }
}
