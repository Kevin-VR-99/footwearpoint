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
        Schema::create('tallas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('sistema', ['MX', 'US', 'EU', 'UK', 'CM', 'OTRO']);
            $table->string('valor', 20);
            $table->decimal('orden')->nullable();
            $table->boolean('activa')->default(true);

            $table->unique(['sistema', 'valor'], 'uq_talla_sistema_valor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tallas');
    }
};
