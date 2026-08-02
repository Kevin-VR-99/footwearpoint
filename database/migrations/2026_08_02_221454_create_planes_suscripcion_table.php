<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('planes_suscripcion', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('nombre', 100)->unique('uq_plan_nombre');
            $table->text('descripcion')->nullable();
            $table->decimal('precio_base_mensual', 12);
            $table->unsignedInteger('lineas_incluidas');
            $table->decimal('precio_linea_extra', 12);
            $table->boolean('activo')->default(true);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planes_suscripcion');
    }
};
