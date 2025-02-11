<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateVariationLocationDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('variation_location_details', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('product_id')->unsigned();


            $table->integer('variation_id')->unsigned();


            $table->integer('location_id')->unsigned();


            $table->decimal('qty_available', 8, 2)->nullable();

            $table->timestamps();

            //Indexing
            $table->index('product_id');
            $table->index('product_variation_id');
            $table->index('variation_id');
        });
	    Schema::table('variation_location_details', function($table) {
		    $table->integer('product_variation_id')->unsigned()->comment('id from product_variations table');
		    $table->foreign('variation_id')->references('id')->on('variations');
		    $table->foreign('location_id')->references('id')->on('business_locations');
	    });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('variation_location_details');
    }
}
