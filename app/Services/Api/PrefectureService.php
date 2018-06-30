<?php

namespace App\Services\Api;

use App\Models\Api\Prefecture;
use App\Models\Api\Region;
use App\Services\CacheService;

class PrefectureService
{
    /*
     * |---------------------------------------------------------------
     * | GET ALL LIST PREFECTURE
     * |---------------------------------------------------------------
     * | @modifined : vungpv @datetime 2017/06/12 05pm
     * |---------------------------------------------------------------
     */
    public static function getList()
    {
	error_log ("*** PrefectureService getList ***\n");
        if ($prefectures = CacheService::get(Prefecture::CACHE_PREFECTURES_KEY)) {
            return $prefectures;
        }
	error_log ("*** PrefectureService getList bbb ***\n");
        $prefectures = Prefecture::getAllPrefecture();
	error_log ("*** PrefectureService getList ccc ***\n");
        CacheService::set(Prefecture::CACHE_PREFECTURES_KEY, $prefectures);
	error_log ("*** PrefectureService getList ddd ***\n");
        return $prefectures;
    }

    /*
     * |---------------------------------------------------------------
     * | GET ALL LIST REGION
     * |---------------------------------------------------------------
     * | @modifined : vungpv @datetime 2017/06/12 05pm
     * |---------------------------------------------------------------
     */
    public static function getListRegions()
    {
        $regions = CacheService::get(Region::CACHE_REGIONS_KEY);
        if (!is_null($regions)) {
            return $regions;
        }
        $regions = Region::getAllRegion();
        $regions = combineData('id', $regions);
        CacheService::set(Region::CACHE_REGIONS_KEY, $regions);
        return $regions;
    }

    /*
     * |---------------------------------------------------------------
     * | GET AREA TREE
     * |---------------------------------------------------------------
     * | @modifined : vungpv @datetime 2017/06/12 05pm
     * |---------------------------------------------------------------
     */
    public static function getAreaTree()
    {
        $regions = CacheService::get(Region::CACHE_REGIONS_PREFECTURE_KEY);
        if (!is_null($regions)) {
            return $regions;
        }
        $regions = Region::getAllRegionPrefecture();
        CacheService::set(Region::CACHE_REGIONS_PREFECTURE_KEY, $regions);
        return $regions;
    }
}
