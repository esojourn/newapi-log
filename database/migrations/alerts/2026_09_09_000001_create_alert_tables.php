<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 额度预警通知的本地存储。
 *
 * 只跑在 alerts 连接（SQLite）上，绝不能落到默认连接——那是外部 newapi 生产库：
 *
 *   php artisan migrate --database=alerts --path=database/migrations/alerts
 */
class CreateAlertTables extends Migration
{
    /** 这几张表只属于 alerts 连接 */
    protected $connection = 'alerts';

    public function up()
    {
        // 用户侧订阅：一个 Key 一行，用户自己填 webhook 与阈值
        Schema::connection($this->connection)->create('alert_subscriptions', function (Blueprint $table) {
            $table->increments('id');
            // tokens.id 而不是 name：name 可以被用户改，id 不会
            $table->unsignedBigInteger('token_id')->unique();
            $table->string('token_name')->index();
            $table->boolean('enabled')->default(false);
            // 阈值存 quota 原始整数（金额 = quota / 500000），避免浮点累积误差
            $table->bigInteger('threshold_quota')->nullable();
            // 密文落盘：sqlite 文件泄露时还需要 APP_KEY 才能拿到 webhook
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->bigInteger('last_notified_quota')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        // 管理员监控名单：共用一个全局 webhook，阈值可逐 Key 覆盖
        Schema::connection($this->connection)->create('alert_admin_watches', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('token_id')->unique();
            $table->string('token_name')->index();
            // null 表示沿用 admin_default_threshold_quota
            $table->bigInteger('threshold_quota')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->bigInteger('last_notified_quota')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        // 管理员全局设置：admin_enabled / admin_webhook_url / admin_webhook_secret
        // / admin_default_threshold_quota
        Schema::connection($this->connection)->create('alert_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::connection($this->connection)->dropIfExists('alert_settings');
        Schema::connection($this->connection)->dropIfExists('alert_admin_watches');
        Schema::connection($this->connection)->dropIfExists('alert_subscriptions');
    }
}
