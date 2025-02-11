<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCategoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('business_id')->unsigned();

            $table->string('short_code')->nullable();
            $table->integer('parent_id');
            $table->integer('created_by')->unsigned();

            $table->softDeletes();
            $table->timestamps();
        });

	    Schema::table('categories', function($table) {
		    $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
		    $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
	    });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('categories');
    }
}
