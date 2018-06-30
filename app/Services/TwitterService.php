<?php
namespace App\Services;

use Abraham\TwitterOAuth\TwitterOAuth;
use GuzzleHttp\Client;

class TwitterService extends BaseService
{
    private $twitterOAuth;
    
    public function __construct()
    {
        $this->twitterOAuth = new TwitterOAuth(
            config('socials.twitter.consumer_key'),
            config('socials.twitter.consumer_secret'),
            config('socials.twitter.access_key'),
            config('socials.twitter.access_key_secret')
        );
        $this->twitterOAuth->setDecodeJsonAsArray(true);
    }
    
    public function search($request)
    {
        $type = $request->get('type');
        switch ($type) {
            case 'users':
                $data = $request->only('screen_name', 'user_id', 'max_id');
                $data['count'] = 10;
                if (!$data['max_id']) {
                    unset($data['max_id']);
                }
                return $this->searchUserTweets($data);
            case 'tweets':
                $data = $request->only('q', 'max_id');
                $data['result_type'] = 'recent';
                $data['count'] = 10;
                return $this->searchTweets($data);
        }
    }

    public function handleItems(&$items)
    {
        foreach ($items as $key => $item) {
            $items[$key]['content'] = $this->replaceContent($item);
        }
    }
    
    private function replaceContent($item)
    {
        $content = $item['text'];
        if (isset($item['entities']['urls'])) {
            foreach ($item['entities']['urls'] as $url) {
                $urlRegex = preg_replace('/\//', '\\/', $url['url']);
                $urlRegex = '/' . $urlRegex . '/';
                $content = preg_replace($urlRegex, '<a target="_blank" href="' . $url['url'] . '">' . $url['display_url'] . '</a>', $content);
            }
        }
    
        if (isset($item['entities']['user_mentions'])) {
            foreach ($item['entities']['user_mentions'] as $user) {
                $regex = '/@' . $user['screen_name'] . '/';
                $content = preg_replace($regex, '<a target="_blank" href="https://twitter.com/' . $user['screen_name'] . '">@' . $user['screen_name'] . '</a>', $content);
            }
        }
        
        return $content;
    }
    
    public function getEmbed($link)
    {
        try {
            $client = new Client();
            $response = $client->get('https://publish.twitter.com/oembed?&url=' . $link);
            $response = json_decode($response->getBody());
    
            return $response;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    private function searchUsers($data)
    {
        return $this->twitterOAuth->get('users/search', $data);
    }
    
    private function searchTweets($data)
    {
        return $this->twitterOAuth->get('search/tweets', $data);
    }
    
    private function searchUserTweets($data)
    {
        return $this->twitterOAuth->get('statuses/user_timeline', $data);
    }
}
