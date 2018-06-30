<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class CacheService extends BaseService
{
    public static function getModelItem($model)
    {
        if (!config('cache.model.enable') ||
            (count(config('cache.model.black_list')) && in_array($model->getTable(), config('cache.model.black_list')))
        ) {
            return null;
        }

        try {
            $redisObject = Redis::get('topical:' . $model->getTable() . ':' . $model->getKey());
            if (!is_null($redisObject)) {
                return json_decode($redisObject, true);
            }
        } catch (\Exception $e) {
        }

        return null;
    }

    public static function setModelItem($model, $item)
    {
        if (!config('cache.model.enable') ||
            (count(config('cache.model.black_list')) && in_array($model->getTable(), config('cache.model.black_list')))
        ) {
            return false;
        }

        try {
            Redis::set('topical:' . $model->getTable() . ':' . $model->getKey(), json_encode($item));

            return Redis::expire('topical:' . $model->getTable() . ':' . $model->getKey(), config('cache.expire_time'));
        } catch (\Exception $e) {
        }

        return false;
    }

    public static function delModelItem($model)
    {
        if (!config('cache.model.enable') ||
            (count(config('cache.model.black_list')) && in_array($model->getTable(), config('cache.model.black_list')))
        ) {
            return false;
        }

        try {
            return Redis::del('topical:' . $model->getTable() . ':' . $model->getKey());
        } catch (\Exception $e) {
        }

        return false;
    }

    public static function get($key)
    {
        if (!config('cache.model.enable')) {
            return null;
        }

        try {
            $redisObject = Redis::get('topical:' . $key);
            if (!is_null($redisObject)) {
                return json_decode($redisObject, true);
            }
        } catch (\Exception $e) {
        }

        return null;
    }

    /*
     * |-----------------------------------------------------------------
     * | set key
     * |-----------------------------------------------------------------
     */
    public static function set($key, $value, $expried = null)
    {
        $expried = !empty($expried) ? $expried : config('cache.expire_time');
        if (!config('cache.model.enable')) {
            return false;
        }
        try {
            Redis::set('topical:' . $key, json_encode($value));
            return Redis::expire('topical:' . $key, $expried);
        } catch (\Exception $e) {
            dd($e);
        }

        return false;
    }

    public static function del($key)
    {
        if (!config('cache.model.enable')) {
            return false;
        }

        try {
            return Redis::del('topical:' . $key);
        } catch (\Exception $e) {
        }

        return false;
    }


    public static function keys($pattern)
    {
        if (!config('cache.model.enable')) {
            return false;
        }

        try {
            return Redis::keys('topical:' . $pattern);
        } catch (\Exception $e) {
        }

        return false;
    }

    /*
     * |-----------------------------------------------------------------
     * | DELETE ALL KEY REDIS
     * |-----------------------------------------------------------------
     * | @author vungpv - @datetime : 2017/06/13 04:30pm
     * |
     * |-----------------------------------------------------------------
     */
}
