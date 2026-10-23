<?php

namespace Tests\Feature\Catalogo;

use App\Models\Linea;
use App\Models\Marca;
use Database\Seeders\LineasMarcasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LineasMarcasSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_carga_las_seis_lineas_y_no_duplica_una_marca_compartida(): void
    {
        $this->seed(LineasMarcasSeeder::class);
        $this->seed(LineasMarcasSeeder::class);

        $this->assertSame(0, Linea::where('nombre', 'Impulss')->count());

        foreach (['Cklass', 'Impuls', 'Dankriz', 'Andrea', 'Price Shoes', 'Mega Shoes'] as $nombre) {
            $linea = Linea::where('nombre', $nombre)->first();
            $this->assertNotNull($linea, $nombre);
            $this->assertGreaterThan(0, $linea->marcas()->count(), $nombre);
        }

        $cklass = Linea::where('nombre', 'Cklass')->first();
        $this->assertTrue($cklass->marcas()->where('nombre', '8 Days')->exists());

        $this->assertSame(1, Marca::where('nombre', 'Flexi')->count());
        $flexi = Marca::where('nombre', 'Flexi')->first();
        $this->assertGreaterThan(1, $flexi->lineas()->count());

        $esperadas = count(require database_path('seeders/datos/lineas_marcas.php'));
        $this->assertSame($esperadas, Linea::whereIn('nombre', [
            'Cklass', 'Impuls', 'Dankriz', 'Andrea', 'Price Shoes', 'Mega Shoes',
        ])->count());
    }
}
