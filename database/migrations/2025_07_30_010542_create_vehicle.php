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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('violators_id')->nullable();
            $table->string('owner_first_name', 100);
            $table->string('owner_middle_name', 100)->nullable();
            $table->string('owner_last_name', 100);
            $table->text('plate_number');
            $table->string('make', 100);
            $table->string('model', 100);
            $table->string('color', 100);
            $table->string('owner_barangay', 255);
            $table->string('owner_city', 255);
            $table->string('owner_province', 255);
            $table->enum('vehicle_type', ['Motor', 'Motorcycle','Van','Car','SUV', 'Truck', 'Bus']);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('violators_id')->references('id')->on('violators')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
