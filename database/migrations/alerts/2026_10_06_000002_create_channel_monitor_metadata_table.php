<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChannelMonitorMetadataTable extends Migration
{
    protected $connection = 'alerts';

    public function up()
    {
        Schema::connection($this->connection)->create('channel_monitor_metadata', function (Blueprint $table) {
            $table->unsignedBigInteger('channel_id')->primary();
            $table->bigInteger('priority')->default(0);
            $table->unsignedBigInteger('weight')->default(0);
            $table->timestamp('updated_at');
        });
    }

    public function down()
    {
        Schema::connection($this->connection)->dropIfExists('channel_monitor_metadata');
    }
}
