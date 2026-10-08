<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-212 (Ola 2, K5) — Lo que cada distribuidora cambia del catálogo (E4-14).
 *
 * El catálogo es uno solo y su precio de menudeo es igual para todos (D8).
 * Lo que sí es de cada distribuidora:
 *
 *   - el precio de mayoreo de un producto, cuando no quiere usar su
 *     descuento general;
 *   - ocultarle un producto a sus clientes.
 *
 * Solo se guarda una fila cuando cambia algo. Sin fila, el producto se ve y su
 * mayoreo sale del descuento general de la distribuidora (sección 4.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ofertas_distribuidora', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->unsignedBigInteger('distribuidora_id');
            $tabla->unsignedBigInteger('producto_campana_id');

            // Vacío = se usa el descuento general de la distribuidora.
            $tabla->decimal('precio_mayorista', 12, 2)->nullable();

            $tabla->boolean('publicado')->default(true);

            $tabla->dateTime('created_at')->useCurrent();
            $tabla->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $tabla->unique(['distribuidora_id', 'producto_campana_id'], 'uq_oferta_distribuidora_producto');

            $tabla->foreign('distribuidora_id', 'fk_ofertas_distribuidora_distribuidora')
                ->references('id')->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');

            $tabla->foreign('producto_campana_id', 'fk_ofertas_distribuidora_producto_campana')
                ->references('id')->on('producto_campana')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ofertas_distribuidora');
    }
};
