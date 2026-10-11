<?php

namespace Tests\Feature\Sprint4;

use App\Models\ImportacionCatalogo;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TG-277 (A8) — La importación guarda el identificador del archivo que
 * devuelve el proveedor de IA.
 *
 * Solo se prueba la columna: que exista, que acepte null y que el modelo la
 * guarde y la lea. Pasar el dato del caché a la columna va en otro PR.
 */
class ArchivoIaDeLaImportacionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Como el que devuelve Anthropic: 29 caracteres. */
    private const ARCHIVO_IA = 'file_01XVEoHeJaS2cMK8eEvH6zeQ';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function importacion(array $extra = []): ImportacionCatalogo
    {
        $admin = Usuario::where('email', 'admin.general@footwearpoint.test')->firstOrFail();

        return ImportacionCatalogo::create($extra + [
            'linea_id' => 1,
            'archivo_url' => 'catalogos-ia/2026/10/prueba.pdf',
            'tipo_archivo' => 'pdf',
            'estado' => 'cargado',
            'iniciada_por_usuario_id' => $admin->id,
        ]);
    }

    public function test_la_columna_existe_en_la_tabla(): void
    {
        $this->assertTrue(Schema::hasColumn('importaciones_catalogo', 'archivo_ia_id'));
    }

    public function test_una_importacion_sin_archivo_subido_la_deja_en_null(): void
    {
        $importacion = $this->importacion();

        $this->assertNull($importacion->fresh()->archivo_ia_id);
    }

    public function test_guarda_y_lee_el_identificador_del_archivo(): void
    {
        $importacion = $this->importacion(['archivo_ia_id' => self::ARCHIVO_IA]);

        $leida = ImportacionCatalogo::findOrFail($importacion->id);

        $this->assertSame(self::ARCHIVO_IA, $leida->archivo_ia_id);
        $this->assertSame(29, strlen($leida->archivo_ia_id));
    }

    public function test_se_puede_guardar_despues_y_tambien_borrar(): void
    {
        $importacion = $this->importacion();

        $importacion->update(['archivo_ia_id' => self::ARCHIVO_IA]);
        $this->assertSame(self::ARCHIVO_IA, $importacion->fresh()->archivo_ia_id);

        $importacion->update(['archivo_ia_id' => null]);
        $this->assertNull($importacion->fresh()->archivo_ia_id);
    }
}
