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
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type', 190);
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('team_id')->default(0)->comment('0 representa asignación global; otro valor representa distribuidora.');

            $table->index(['model_id', 'model_type'], 'ix_mhp_model');
            $table->primary(['permission_id', 'model_id', 'model_type', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
    }
};
