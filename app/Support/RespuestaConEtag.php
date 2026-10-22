<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si el cliente manda el mismo ETag, se contesta 304 sin cuerpo.
 * Así la app no vuelve a bajar el catálogo o la bandeja cuando no cambiaron.
 */
class RespuestaConEtag
{
    public static function desde(Request $request, JsonResponse $respuesta): Response
    {
        $json = $respuesta->getContent();
        $etag = '"'.sha1($json).'"';

        if (self::coincide($request->headers->get('If-None-Match'), $etag)) {
            return response('', 304)->header('ETag', $etag);
        }

        $respuesta->headers->set('ETag', $etag);

        return $respuesta;
    }

    public static function coincide(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }

        $actual = trim($etag, '"');

        foreach (explode(',', $ifNoneMatch) as $candidato) {
            $pedido = trim(str_replace('W/', '', $candidato), " \t\"");

            if ($pedido !== '' && hash_equals($actual, $pedido)) {
                return true;
            }
        }

        return false;
    }
}
