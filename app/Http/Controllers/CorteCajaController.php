<?php

namespace App\Http\Controllers;

use App\Models\CajaSesion;
use App\Services\Auth\PermisoModuloService;
use App\Services\Caja\CajaService;
use App\Support\ContextoOperativo;
use App\Support\MensajeError;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** PDF del corte de caja. La caja de otra distribuidora da 404 (scope del tenant). */
class CorteCajaController extends Controller
{
    public function pdf(Request $request, int $id, CajaService $cajas, ContextoOperativo $contexto): Response
    {
        abort_unless(app(PermisoModuloService::class)->puede($request->user(), PermisoModuloService::CAJA_OPERAR), 403, MensajeError::SIN_PERMISO);

        $caja = CajaSesion::query()->with(['abiertaPor.usuario', 'cerradaPor.usuario', 'sucursal', 'distribuidora'])->findOrFail($id);

        // El empleado solo descarga sus cortes.
        if (! $request->user()->hasRole('admin_distribuidora')) {
            abort_unless((int) $caja->abierta_por_staff_id === (int) $contexto->staff()->id, 403, MensajeError::SIN_PERMISO);
        }

        $pdf = Pdf::loadView('caja.corte-pdf', ['caja' => $caja, 'r' => $cajas->resumen($caja)])->setPaper('letter');

        return $pdf->download('corte-caja-'.$caja->id.'-'.($caja->cerrada_at ?? now())->format('Ymd-Hi').'.pdf');
    }
}
