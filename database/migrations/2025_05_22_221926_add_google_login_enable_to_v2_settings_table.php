<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddGoogleLoginEnableToV2SettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 检查是否已存在，避免重复插入
        if (!DB::table('v2_settings')->where('name', 'google_login_enable')->exists()) {
            DB::table('v2_settings')->insert([
                'name' => 'google_login_enable',
                'value' => '0', // 默认关闭
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('v2_settings')->where('name', 'google_login_enable')->delete();
    }
}
