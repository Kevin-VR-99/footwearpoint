<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\AgregarImagenProductoCampanaRequest;
use App\Http\Resources\Catalogo\ImagenProductoCampanaResource;
use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Services\Catalogo\GestionarImagenProductoCampanaAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ImagenProductoCampanaController extends Controller
{
    public function index(ProductoCampana $productoCampana): AnonymousResourceCollection
    {
        return ImagenProductoCampanaResource::collection(
            $productoCampana->imagenes()->orderBy('orden')->get()
        );
    }

    public function store(
        AgregarImagenProductoCampanaRequest $request,
        ProductoCampana $productoCampana,
        GestionarImagenProductoCampanaAction $accion
    ): ImagenProductoCampanaResource {
        $imagen = $accion->agregar(
            $productoCampana,
            $request->file('imagen'),
            (bool) $request->validated('es_principal', false)
        );

        return new ImagenProductoCampanaResource($imagen);
    }

    /**
     * Único uso de este PATCH: marcar una imagen ya existente como
     * principal. No se edita la URL ni el orden aquí (la tabla ni
     * siquiera tiene updated_at — no está pensada para editarse, solo
     * para agregar/quitar).
     */
    public function marcarPrincipal(
        ProductoImagen $imagen,
        GestionarImagenProductoCampanaAction $accion
    ): ImagenProductoCampanaResource {
        return new ImagenProductoCampanaResource($accion->marcarPrincipal($imagen));
    }

    public function destroy(ProductoImagen $imagen, GestionarImagenProductoCampanaAction $accion): JsonResponse
    {
        $accion->eliminar($imagen);

        return response()->json(['data' => null, 'message' => 'Imagen eliminada.']);
    }
}
