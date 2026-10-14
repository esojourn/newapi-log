<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddHourlyAlertsToAdminWatches extends Migration
{
    protected $connection = 'alerts';

    public function up()
    {
        Schema::connection($this->connection)->table('alert_admin_watches', function (Blueprint $table) {
            $table->boolean('hourly_alert_enabled')->default(false);
            $table->bigInteger('hourly_threshold_quota')->nullable();
            $table->timestamp('last_hourly_notified_at')->nullable();
            // 被通知的小时起点（Unix 时间戳），与余额预警单独去重。
            $table->unsignedBigInteger('last_hourly_notified_hour')->nullable();
            $table->bigInteger('last_hourly_notified_quota')->nullable();
            $table->text('last_hourly_error')->nullable();
            $table->timestamp('last_hourly_error_at')->nullable();
        });
    }

    public function down()
    {
        $connection = DB::connection($this->connection);
        $schema = Schema::connection($this->connection);

        // Laravel 8 在 SQLite 上 dropColumn 依赖本项目未安装的 doctrine/dbal。
        // 在事务里重建原表，兼容旧版 SQLite，同时保留原有余额设置与推送记录。
        $connection->transaction(function () use ($connection, $schema) {
            $temporaryTable = 'alert_admin_watches_without_hourly_alerts';
            $schema->create($temporaryTable, function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedBigInteger('token_id')->unique();
                $table->string('token_name');
                $table->bigInteger('threshold_quota')->nullable();
                $table->timestamp('last_notified_at')->nullable();
                $table->bigInteger('last_notified_quota')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('last_error_at')->nullable();
                $table->timestamps();
            });

            $columns = [
                'id', 'token_id', 'token_name', 'threshold_quota', 'last_notified_at',
                'last_notified_quota', 'last_error', 'last_error_at', 'created_at', 'updated_at',
            ];
            $connection->table($temporaryTable)->insertUsing($columns, $connection->table('alert_admin_watches')->select($columns));
            $schema->drop('alert_admin_watches');
            $schema->rename($temporaryTable, 'alert_admin_watches');
            $schema->table('alert_admin_watches', function (Blueprint $table) {
                $table->index('token_name');
            });
        });
    }
}
