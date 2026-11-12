<?php

namespace Tests\Feature\Legales;

use App\Support\DocumentosLegales;
use Tests\TestCase;

/**
 * TG-323 (K24) — Candado: no se publica un texto nuevo con una versión vieja.
 *
 * La huella se saca del archivo del documento sin comentarios de Blade y con
 * los espacios juntados, así que reacomodar líneas o cambiar comentarios no
 * pide versión nueva; cambiar cualquier otra cosa, sí.
 */
class DocumentosLegalesHuellaTest extends TestCase
{
    public static function huella(string $tipo): string
    {
        $archivo = resource_path('views/'.config("legal.documentos.{$tipo}.vista"));
        $texto = (string) file_get_contents($archivo);
        $texto = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $texto);
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));

        return hash('sha256', $texto);
    }

    /** @return array<string, array<string, string>> */
    private function huellas(): array
    {
        return require __DIR__.'/huellas.php';
    }

    public function test_el_texto_de_cada_documento_corresponde_a_su_version_vigente(): void
    {
        foreach (DocumentosLegales::tipos() as $tipo) {
            $version = DocumentosLegales::version($tipo);
            $actual = self::huella($tipo);
            $registrada = $this->huellas()[$tipo][$version] ?? null;

            $this->assertNotNull($registrada,
                "La versión {$version} de {$tipo} no está en tests/Feature/Legales/huellas.php. "
                ."Si es nueva, agrégala con la huella {$actual}.");

            $this->assertSame($registrada, $actual,
                "El texto de {$tipo} cambió pero su versión sigue en {$version}. "
                ."Sube la versión y la fecha en config/legal.php y agrega la versión nueva en huellas.php con la huella {$actual}. "
                .'No cambies la huella de una versión ya publicada.');
        }
    }

    /** Dos versiones con el mismo texto serían una versión inventada. */
    public function test_cada_version_registrada_tiene_un_texto_distinto(): void
    {
        foreach ($this->huellas() as $tipo => $versiones) {
            $this->assertSame(count($versiones), count(array_unique($versiones)), "Hay versiones de {$tipo} con el mismo texto.");
        }
    }

    public function test_cada_documento_tiene_version_fecha_y_pagina(): void
    {
        $this->assertSame([DocumentosLegales::AVISO, DocumentosLegales::TERMINOS], DocumentosLegales::tipos());

        foreach (DocumentosLegales::tipos() as $tipo) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+$/', DocumentosLegales::version($tipo));
            $this->assertNotSame('', DocumentosLegales::fecha($tipo));
            $this->assertFileExists(resource_path('views/'.config("legal.documentos.{$tipo}.vista")));
            $this->assertStringStartsWith('http', DocumentosLegales::url($tipo));
        }
    }
}
