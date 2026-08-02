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
        Schema::create('usuarios', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('nombre', 150)->comment('Nombre completo.');
            $table->string('email', 190)->unique('uq_usuarios_email')->comment('Correo de acceso global.');
            $table->string('password')->comment('Hash de contraseña.');
            $table->string('telefono', 30)->nullable();
            $table->enum('estado', ['activo', 'bloqueado', 'inactivo'])->default('activo');
            $table->dateTime('email_verified_at')->nullable();
            $table->rememberToken();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
