<?php
namespace App\Services;

use Alaouy\Youtube\Facades\Youtube;

class YoutubeService extends BaseService
{
    public static function search($request)
    {
        $params = [
            'q' => $request['keyword'],
            'type' => 'video',
            'part' => 'id,snippet',
            'maxResults' => config('youtube.page_size'),
        ];
        
        return Youtube::paginateResults($params, $request['token']);
    }
}
