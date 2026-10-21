<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Auditoría 21-oct-2026 — Recibe los avisos del navegador cuando la CSP
 * (Report-Only) habría bloqueado algo. Solo deja una línea en el log; nunca
 * guarda el cuerpo completo.
 */
class ReporteCspController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $datos = json_decode((string) $request->getContent(), true);
        $reporte = is_array($datos) ? ($datos['csp-report'] ?? $datos[0]['body'] ?? $datos) : [];
        $reporte = is_array($reporte) ? $reporte : [];

        $campo = fn (string ...$llaves) => collect($llaves)->map(fn ($l) => $reporte[$l] ?? null)->first(fn ($v) => is_scalar($v));

        Log::warning('CSP: el navegador reportó un recurso que se bloquearía.', [
            'directiva' => Str::limit((string) $campo('violated-directive', 'effectiveDirective', 'effective-directive'), 100),
            'bloqueado' => Str::limit((string) $campo('blocked-uri', 'blockedURL'), 200),
            'pagina'    => Str::limit((string) $campo('document-uri', 'documentURL'), 200),
            'origen'    => Str::limit((string) $campo('source-file', 'sourceFile'), 200),
            'linea'     => (int) $campo('line-number', 'lineNumber'),
        ]);

        return response()->noContent();
    }
}
