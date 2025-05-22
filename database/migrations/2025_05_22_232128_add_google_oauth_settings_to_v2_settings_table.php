<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddGoogleOauthSettingsToV2SettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 插入 google_client_id
        if (!DB::table('v2_settings')->where('name', 'google_client_id')->exists()) {
            DB::table('v2_settings')->insert([
                'name' => 'google_client_id',
                'value' => '', // 默认值为空，需管理员手动设置
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 插入 google_client_secret
        if (!DB::table('v2_settings')->where('name', 'google_client_secret')->exists()) {
            DB::table('v2_settings')->insert([
                'name' => 'google_client_secret',
                'value' => '', // 默认值为空，需管理员手动设置
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 插入 google_redirect_uri
        if (!DB::table('v2_settings')->where('name', 'google_redirect_uri')->exists()) {
            DB::table('v2_settings')->insert([
                'name' => 'google_redirect_uri',
                'value' => 'http://your-app.com/passport/auth/google/callback', // 默认回调 URL，需管理员调整
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
        DB::table('v2_settings')->whereIn('name', [
            'google_client_id',
            'google_client_secret',
            'google_redirect_uri',
        ])->delete();
    }
}
