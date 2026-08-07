<?php

namespace Database\Seeders;

use App\Models\Distribuidora;
use App\Models\Linea;
use App\Models\Marca;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MarcasDemoSeeder extends Seeder
{
    public function run(): void
    {
        $distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->first();

        if (! $distribuidora) {
            $this->command?->warn('No existe calzados-ramirez. Omite MarcasDemoSeeder.');
            return;
        }

        $nombres = [
            'Nike',
            'Adidas',
            'Puma',
            'Reebok',
            'New Balance',
            'Converse',
            'Vans',
            'Under Armour',
            'Skechers',
            'Crocs',
            'Flexi',
            'Tropicana',
            'Steve Madden',
            'Caterpillar',
            'Timberland',
        ];

        $marcaIds = [];

        foreach ($nombres as $nombre) {
            $marca = Marca::withoutGlobalScopes()->firstOrCreate(
                [
                    'distribuidora_id' => $distribuidora->id,
                    'nombre'           => $nombre,
                ],
                [
                    'descripcion' => "Marca {$nombre}",
                    'activa'      => true,
                ]
            );

            $marcaIds[] = $marca->id;
        }

        // Opcional: asociar todas las marcas a cada línea activa (N:N compartido)
        $lineas = Linea::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('activa', true)
            ->get();

        foreach ($lineas as $linea) {
            foreach ($marcaIds as $marcaId) {
                $existe = DB::table('linea_marca')
                    ->where('linea_id', $linea->id)
                    ->where('marca_id', $marcaId)
                    ->exists();

                if (! $existe) {
                    DB::table('linea_marca')->insert([
                        'distribuidora_id' => $distribuidora->id,
                        'linea_id'         => $linea->id,
                        'marca_id'         => $marcaId,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
                }
            }
        }

        $this->command?->info(count($nombres) . ' marcas conocidas creadas/actualizadas y asociadas a líneas activas.');
    }
}