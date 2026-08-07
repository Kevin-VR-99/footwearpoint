<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Líneas (pertenecen a una campaña; consumen cupo del plan)
        Schema::create('lineas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('distribuidora_id');
            $table->unsignedBigInteger('campana_id');
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->boolean('activa')->default(true);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['distribuidora_id', 'id'], 'uq_lineas_tenant_id');
            $table->unique(['distribuidora_id', 'campana_id', 'nombre'], 'uq_linea_tenant_campana_nombre');
        });

        Schema::table('lineas', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_lineas_1')
                ->references(['id'])->on('distribuidoras')
                ->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'campana_id'], 'fk_lineas_2')
                ->references(['distribuidora_id', 'id'])->on('campanas')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        // 2) Pivote N:N línea ↔ marca
        Schema::create('linea_marca', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('distribuidora_id');
            $table->unsignedBigInteger('linea_id');
            $table->unsignedBigInteger('marca_id');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['linea_id', 'marca_id'], 'uq_linea_marca');
            $table->unique(['distribuidora_id', 'id'], 'uq_linea_marca_tenant_id');
        });

        Schema::table('linea_marca', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_linea_marca_1')
                ->references(['id'])->on('distribuidoras')
                ->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'linea_id'], 'fk_linea_marca_2')
                ->references(['distribuidora_id', 'id'])->on('lineas')
                ->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'marca_id'], 'fk_linea_marca_3')
                ->references(['distribuidora_id', 'id'])->on('marcas')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        // 3) Producto apunta a línea (+ marca que ya tenía)
        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedBigInteger('linea_id')->nullable()->after('marca_id');
        });

        // 4) Campaña: marca_id pasa a nullable (ya no es el dueño del árbol)
        Schema::table('campanas', function (Blueprint $table) {
            $table->dropForeign('fk_campanas_2');
        });

        // MySQL: unique compuesto con marca; lo recreamos permitiendo null
        Schema::table('campanas', function (Blueprint $table) {
            $table->dropUnique('uq_campana_tenant_marca_nombre');
        });

        Schema::table('campanas', function (Blueprint $table) {
            $table->unsignedBigInteger('marca_id')->nullable()->change();
        });

        Schema::table('campanas', function (Blueprint $table) {
            $table->unique(['distribuidora_id', 'nombre'], 'uq_campana_tenant_nombre');
            $table->foreign(['distribuidora_id', 'marca_id'], 'fk_campanas_2')
                ->references(['distribuidora_id', 'id'])->on('marcas')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        // 5) Backfill: 1 línea por campaña a partir de la marca actual
        $campanas = DB::table('campanas')->get();

        foreach ($campanas as $campana) {
            if ($campana->marca_id === null) {
                continue;
            }

            $marca = DB::table('marcas')->where('id', $campana->marca_id)->first();
            $nombreLinea = $marca ? ('Línea ' . $marca->nombre) : ('Línea campaña ' . $campana->id);

            $lineaId = DB::table('lineas')->insertGetId([
                'distribuidora_id' => $campana->distribuidora_id,
                'campana_id'       => $campana->id,
                'nombre'           => $nombreLinea,
                'descripcion'      => 'Migrada automáticamente desde el modelo anterior',
                'activa'           => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            DB::table('linea_marca')->insert([
                'distribuidora_id' => $campana->distribuidora_id,
                'linea_id'         => $lineaId,
                'marca_id'         => $campana->marca_id,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            DB::table('productos')
                ->where('distribuidora_id', $campana->distribuidora_id)
                ->where('marca_id', $campana->marca_id)
                ->whereNull('linea_id')
                ->update(['linea_id' => $lineaId]);
        }
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('linea_id');
        });

        Schema::table('linea_marca', function (Blueprint $table) {
            $table->dropForeign('fk_linea_marca_1');
            $table->dropForeign('fk_linea_marca_2');
            $table->dropForeign('fk_linea_marca_3');
        });
        Schema::dropIfExists('linea_marca');

        Schema::table('lineas', function (Blueprint $table) {
            $table->dropForeign('fk_lineas_1');
            $table->dropForeign('fk_lineas_2');
        });
        Schema::dropIfExists('lineas');

        // No revertimos marca_id nullable en down para no complicar el demo.
    }
};