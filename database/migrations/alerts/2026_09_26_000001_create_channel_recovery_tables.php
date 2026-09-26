<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChannelRecoveryTables extends Migration
{
    protected $connection = 'alerts';

    public function up()
    {
        Schema::connection($this->connection)->create('channel_recovery_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('schedule_cron', 100)->default('*/5 * * * *');
            $table->string('base_url', 500)->nullable();
            $table->text('access_token')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('http_timeout')->default(30);
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('channel_recovery_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('channel_id')->index();
            $table->string('channel_name')->nullable();
            $table->string('source', 20);
            $table->unsignedTinyInteger('from_status')->default(3);
            $table->unsignedTinyInteger('target_status')->default(1);
            $table->string('result', 20)->index();
            $table->string('message', 500);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::connection($this->connection)->dropIfExists('channel_recovery_logs');
        Schema::connection($this->connection)->dropIfExists('channel_recovery_settings');
    }
}
