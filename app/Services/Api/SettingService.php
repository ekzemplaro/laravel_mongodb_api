<?php

namespace App\Services\Api;

use App\Models\Api\Setting;
use App\Services\CacheService;

class SettingService
{
    public static function getTopicCreateLimitation()
    {
        $setting = CacheService::get(Setting::SETTING_CREATE_TOPIC);
        if (is_null($setting)) {
            $setting =  Setting::where('type', config('setting.type.limit_topic'))
            ->first(['unlimited', 'limit_topic_user_logged_anonymous', 'limit_topic_user_logged'])
            ->toArray();

            CacheService::set(Setting::SETTING_CREATE_TOPIC, $setting);
        }

        return $setting;
    }

    public static function getCommentCreateLimitation()
    {
        $setting = CacheService::get(Setting::SETTING_CREATE_COMMENT);
        if (is_null($setting)) {
            $setting = Setting::where('type', config('setting.type.limit_comment'))
            ->first(['unlimited', 'limit_comment_user_logged', 'limit_comment_not_login', 'limit_comment_user_logged_anonymous'])
            ->toArray();

            CacheService::set(Setting::SETTING_CREATE_COMMENT, $setting);
        }

        return $setting;
    }

    public static function getDeleteAuthority()
    {
        $setting = CacheService::get(Setting::SETTING_DELETE_AUTHORITY);
        if (is_null($setting)) {
            $setting = Setting::where('type', config('setting.type.delete_authority'))
                ->first()
                ->toArray();

            CacheService::set(Setting::SETTING_DELETE_AUTHORITY, $setting);
        }

        return $setting;
    }
}
