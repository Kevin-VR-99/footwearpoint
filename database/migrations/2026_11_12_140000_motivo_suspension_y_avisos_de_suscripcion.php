<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separa la suspensión por falta de pago de la manual y guarda qué aviso
 * de vencimiento ya se mandó, para que el comando diario no lo repita.
 *
 * Una distribuidora ya suspendida queda con motivo nulo: se trata como
 * manual, así que un pago no la reactiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidoras', function (Blueprint $tabla) {
            $tabla->string('motivo_suspension', 32)->nullable()->after('motivo_rechazo');
        });

        Schema::create('suscripcion_avisos', function (Blueprint $tabla) {
            $tabla->bigIncrements('id');
            $tabla->unsignedBigInteger('distribuidora_id');
            $tabla->unsignedBigInteger('suscripcion_id');
            $tabla->string('tipo', 32);
            $tabla->date('fecha');
            $tabla->dateTime('created_at')->useCurrent();

            $tabla->unique(['suscripcion_id', 'tipo', 'fecha'], 'uq_suscripcion_aviso_dia');
            $tabla->index(['distribuidora_id', 'fecha'], 'ix_suscripcion_aviso_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suscripcion_avisos');

        Schema::table('distribuidoras', function (Blueprint $tabla) {
            $tabla->dropColumn('motivo_suspension');
        });
    }
};
