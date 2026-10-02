<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChannelRecoveryRunsTable extends Migration
{
    protected $connection = 'alerts';

    public function up()
    {
        // 只保留最近一轮（id 固定为 1），历史汇总仍在 laravel.log。
        Schema::connection($this->connection)->create('channel_recovery_runs', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('source', 20);
            $table->boolean('dry_run')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('checked')->default(0);
            $table->unsignedInteger('healthy')->default(0);
            $table->unsignedInteger('recovered')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('error')->nullable();
            $table->text('failures')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::connection($this->connection)->dropIfExists('channel_recovery_runs');
    }
}
