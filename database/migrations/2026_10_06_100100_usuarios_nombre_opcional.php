<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TG-216 (Ola 2, K9) — El nombre de la cuenta pasa a ser opcional.
 *
 * El nombre y el teléfono de un revendedor o de un cliente directo viven solo
 * en su registro de contacto (revendedores / clientes_directos), que es el que
 * edita el panel y el que ve el empleado. La cuenta (usuarios) guarda nada más
 * el acceso: correo, contraseña y estado (sección 5 del diseño).
 *
 * El personal (admin general, admins y empleados) SÍ sigue usando
 * usuarios.nombre, y para ellos sigue siendo obligatorio: eso se valida al
 * crearlos, no con la columna.
 *
 * No se tocan los datos que ya existen: en K12 la base se reconstruye desde
 * cero con migrate:fresh --seed (D6).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `usuarios` MODIFY `nombre` VARCHAR(150) NULL COMMENT 'Nombre completo. Solo lo usa el personal: el de revendedores y clientes vive en su contacto.'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `usuarios` MODIFY `nombre` VARCHAR(150) NOT NULL COMMENT 'Nombre completo.'");
    }
};
