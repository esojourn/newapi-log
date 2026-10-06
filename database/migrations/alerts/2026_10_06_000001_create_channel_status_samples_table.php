<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChannelStatusSamplesTable extends Migration
{
    protected $connection = 'alerts';

    public function up()
    {
        Schema::connection($this->connection)->create('channel_status_samples', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('channel_id');
            $table->string('channel_name')->nullable();
            $table->integer('status');
            $table->timestamp('observed_at');
            $table->timestamp('expires_at');
            $table->index(['channel_id', 'observed_at']);
        });
    }

    public function down()
    {
        Schema::connection($this->connection)->dropIfExists('channel_status_samples');
    }
}
