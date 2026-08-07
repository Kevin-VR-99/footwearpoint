<?php

namespace Database\Seeders;

use App\Models\Linea;
use App\Models\Producto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BackfillProductoLineaSeeder extends Seeder
{
    public function run(): void
    {
        $productos = Producto::withoutGlobalScopes()
            ->whereNull('linea_id')
            ->get();

        $asignados = 0;

        foreach ($productos as $producto) {
            $lineaId = DB::table('linea_marca')
                ->where('distribuidora_id', $producto->distribuidora_id)
                ->where('marca_id', $producto->marca_id)
                ->value('linea_id');

            if (! $lineaId) {
                $lineaId = Linea::withoutGlobalScopes()
                    ->where('distribuidora_id', $producto->distribuidora_id)
                    ->where('activa', true)
                    ->orderBy('id')
                    ->value('id');
            }

            if (! $lineaId) {
                continue;
            }

            $existe = DB::table('linea_marca')
                ->where('linea_id', $lineaId)
                ->where('marca_id', $producto->marca_id)
                ->exists();

            if (! $existe) {
                DB::table('linea_marca')->insert([
                    'distribuidora_id' => $producto->distribuidora_id,
                    'linea_id'         => $lineaId,
                    'marca_id'         => $producto->marca_id,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            $producto->linea_id = $lineaId;
            $producto->save();
            $asignados++;
        }

        $this->command?->info("Productos con línea asignada: {$asignados}");
    }
}