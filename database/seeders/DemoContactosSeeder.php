<?php

namespace Database\Seeders;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use Illuminate\Database\Seeder;

class DemoContactosSeeder extends Seeder
{
    public function run(): void
    {
        $distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();

        // --- 2 revendedores de prueba ---
        // Nota: sin usuario_id porque el revendedor todavía no tiene cuenta
        // propia en este sprint (decisión D2) — solo datos de contacto.
        $revendedoresDemo = [
            ['nombre' => 'María López', 'telefono' => '9635551111', 'email' => 'maria.lopez@revendedor.test'],
            ['nombre' => 'Roberto García', 'telefono' => '9635552222', 'email' => 'roberto.garcia@revendedor.test'],
        ];

        foreach ($revendedoresDemo as $i => $datos) {
            $revendedor = Revendedor::firstOrCreate(
                ['email' => $datos['email']],
                [
                    'nombre' => $datos['nombre'],
                    'telefono' => $datos['telefono'],
                    'estado' => 'activo',
                ]
            );

            RevendedorDistribuidora::firstOrCreate(
                ['distribuidora_id' => $distribuidora->id, 'revendedor_id' => $revendedor->id],
                [
                    'codigo_interno' => 'REV-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                    'estado' => 'activo',
                    'fecha_alta' => now(),
                ]
            );
        }

        // --- 3 clientes directos de prueba ---
        // Tampoco tienen usuario_id: en este sprint los captura el empleado.
        $clientesDemo = [
            ['nombre' => 'Ana García', 'telefono' => '9635553333', 'email' => 'ana.garcia@cliente.test'],
            ['nombre' => 'José Hernández', 'telefono' => '9635554444', 'email' => 'jose.hernandez@cliente.test'],
            ['nombre' => 'Laura Pérez', 'telefono' => '9635555555', 'email' => 'laura.perez@cliente.test'],
        ];

        foreach ($clientesDemo as $datos) {
            ClienteDirecto::firstOrCreate(
                ['distribuidora_id' => $distribuidora->id, 'email' => $datos['email']],
                [
                    'nombre' => $datos['nombre'],
                    'telefono' => $datos['telefono'],
                    'estado' => 'activo',
                ]
            );
        }

        $this->command->info('Contactos demo creados: 2 revendedores afiliados y 3 clientes directos.');
    }
}