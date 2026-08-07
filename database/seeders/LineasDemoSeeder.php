<?php

namespace Database\Seeders;

use App\Models\Campana;
use App\Models\Distribuidora;
use App\Models\Linea;
use App\Models\Marca;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LineasDemoSeeder extends Seeder
{
    public function run(): void
    {
        $distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->first();

        if (! $distribuidora) {
            $this->command?->warn('No existe la distribuidora calzados-ramirez. Omite LineasDemoSeeder.');
            return;
        }

        $campana = Campana::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('estado', 'activa')
            ->first();

        if (! $campana) {
            $campana = Campana::withoutGlobalScopes()
                ->where('distribuidora_id', $distribuidora->id)
                ->orderBy('id')
                ->first();
        }

        if (! $campana) {
            $this->command?->warn('No hay campaña para calzados-ramirez. Omite LineasDemoSeeder.');
            return;
        }

        $nombres = [
            'Cklass',
            'Impuls',
            'Dankriz',
            'Andrea',
            'Concord',
        ];

        foreach ($nombres as $nombre) {
            $linea = Linea::withoutGlobalScopes()->firstOrCreate(
                [
                    'distribuidora_id' => $distribuidora->id,
                    'campana_id'       => $campana->id,
                    'nombre'           => $nombre,
                ],
                [
                    'descripcion' => "Línea comercial {$nombre}",
                    'activa'      => true,
                ]
            );

            // Marca homónima (compartible entre líneas) y pivote N:N
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

            $existe = DB::table('linea_marca')
                ->where('linea_id', $linea->id)
                ->where('marca_id', $marca->id)
                ->exists();

            if (! $existe) {
                DB::table('linea_marca')->insert([
                    'distribuidora_id' => $distribuidora->id,
                    'linea_id'         => $linea->id,
                    'marca_id'         => $marca->id,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }

        $this->command?->info('5 líneas creadas/actualizadas: Cklass, Impuls, Dankriz, Andrea, Concort.');
    }
}