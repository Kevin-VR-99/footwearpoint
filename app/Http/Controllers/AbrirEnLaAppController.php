<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Página pública del enlace que se manda por WhatsApp.
 * Si la app está instalada, Android abre el pedido o el producto.
 * Si no, esta página ofrece el mismo enlace.
 */
class AbrirEnLaAppController extends Controller
{
    public function __invoke(string $tipo, int $id): View
    {
        abort_unless(in_array($tipo, ['pedido', 'producto'], true), 404);

        return view('abrir-en-app', [
            'tipo' => $tipo,
            'id'   => $id,
            'url'  => 'https://footwearpoint.app/abrir/'.$tipo.'/'.$id,
        ]);
    }
}
