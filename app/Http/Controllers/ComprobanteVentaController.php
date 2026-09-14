<?php

namespace App\Http\Controllers;

use App\Mail\ComprobanteVentaMail;
use App\Models\VentaDirecta;
use App\Services\VentaDirecta\DatosComprobanteVentaAction;
use App\Support\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Comprobante de venta directa (E7-02 / TG-115): verlo e imprimirlo,
 * descargarlo en PDF y mandarlo por correo.
 *
 * Solo admin_distribuidora y empleado (lo exige la ruta). La venta se busca
 * con el TenantScope activo: la de otra distribuidora responde 404, igual
 * que en el resto del sistema, sin confirmar siquiera que existe.
 */
class ComprobanteVentaController extends Controller
{
    public const VISTA = 'comprobantes.venta-directa';

    public function __construct(private DatosComprobanteVentaAction $datos)
    {
    }

    public function show(int $id): View
    {
        return view(self::VISTA, [
            'c'    => $this->datos->ejecutar($this->venta($id)),
            'modo' => 'pantalla',
        ]);
    }

    public function pdf(int $id): Response
    {
        $comprobante = $this->datos->ejecutar($this->venta($id));

        return self::generarPdf($comprobante)->download(self::nombreArchivo($comprobante));
    }

    public function enviar(Request $request, int $id): RedirectResponse
    {
        $comprobante = $this->datos->ejecutar($this->venta($id));

        $datos = $request->validate([
            'email' => ['required', 'email', 'max:190'],
        ], [
            'email.required' => 'Escribe el correo al que se enviará el comprobante.',
            'email.email'    => 'El correo no es válido.',
        ]);

        Mail::to($datos['email'])->send(new ComprobanteVentaMail($comprobante));

        return redirect()
            ->route('ventas-directas.comprobante', $id)
            ->with('status', 'Comprobante enviado a ' . $datos['email'] . '.');
    }

    /** Lo usan la descarga y el adjunto del correo: mismo PDF en los dos. */
    public static function generarPdf(array $comprobante): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView(self::VISTA, ['c' => $comprobante, 'modo' => 'pdf'])
            ->setPaper('letter');
    }

    public static function nombreArchivo(array $comprobante): string
    {
        return 'comprobante-' . $comprobante['folio'] . '.pdf';
    }

    private function venta(int $id): VentaDirecta
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        return VentaDirecta::query()->findOrFail($id);
    }
}
