<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos para que el cliente pague por SPEI a la cuenta de la distribuidora,
 * y el archivo que sube como comprobante. Solo agrega. No borra datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->string('banco', 80)->nullable()->after('stock_minimo_general');
            $tabla->char('clabe', 18)->nullable()->after('banco');
            $tabla->string('titular_cuenta', 150)->nullable()->after('clabe');
        });

        Schema::create('comprobantes_transferencia', function (Blueprint $tabla) {
            $tabla->bigIncrements('id');
            $tabla->unsignedBigInteger('distribuidora_id');
            $tabla->unsignedBigInteger('pedido_id');
            $tabla->string('disco', 40);
            $tabla->string('ruta', 255);
            $tabla->string('nombre_original', 190);
            $tabla->string('mime', 80);
            $tabla->unsignedInteger('tamano');
            $tabla->unsignedBigInteger('subido_por_usuario_id')->nullable();
            $tabla->dateTime('created_at')->useCurrent();
            $tabla->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $tabla->unique('pedido_id', 'uq_comprobante_transferencia_pedido');
            $tabla->index('distribuidora_id', 'ix_comprobante_transferencia_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes_transferencia');

        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->dropColumn(['banco', 'clabe', 'titular_cuenta']);
        });
    }
};
