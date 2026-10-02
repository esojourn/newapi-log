<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChannelMonitorLogsTable extends Migration
{
    protected $connection = 'alerts';

    public function up()
    {
        Schema::connection($this->connection)->create('channel_monitor_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('channel_id')->index();
            $table->string('channel_name')->nullable();
            $table->string('source', 20);
            $table->boolean('dry_run')->default(false);
            $table->timestamp('disabled_at')->nullable();
            $table->text('disabled_reason')->nullable();
            $table->string('result', 20)->index();
            $table->text('message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::connection($this->connection)->dropIfExists('channel_monitor_logs');
    }
}
