<?php

namespace Firebrand\Hail\Api;

use Firebrand\Hail\Models\Article;
use Firebrand\Hail\Models\Image;
use Firebrand\Hail\Models\Organisation;
use Firebrand\Hail\Models\Video;
use GuzzleHttp\Client as HTTPClient;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * API client for the Hail Api. It uses Guzzle HTTP CLient to communicate with
 * with Hail.
 * *
 * An Client ID and a Client Secret must be provided in your .env file for
 * HailAPI
 *
 * Errors are shown in the CMS via session variables and logged to file
 *
 * @package silverstripe-hail
 * @author Marc Espiard, Firebrand
 * @version 1.0
 *
 */
class Client
{
    private $client_id;
    private $client_secret;
    private $access_token;
    private $access_token_expire;
    private $refresh_token;
    private $user_id;
    private $orgs_ids;
    private $scopes = "user.basic content.read";

    public function __construct()
    {
        //Get Client ID and Secret from env file
        $this->client_id = Environment::getEnv('HAIL_CLIENT_ID');;
        $this->client_secret = Environment::getEnv('HAIL_CLIENT_SECRET');;

        //Get api settings from site config
        $config = SiteConfig::current_site_config();
        $this->access_token = $config->HailAccessToken;
        $this->access_token_expire = $config->HailAccessTokenExpire;
        $this->refresh_token = $config->HailRefreshToken;
        $this->user_id = $config->HailUserID;
        $this->orgs_ids = $config->HailOrgsIDs;
    }

    /**
     * Send a GET request to the Hail API for a specific URI and returns
     * the results. Extra parameters can be passed with the $body variable.
     *
     * @param string $uri Resource to get
     * @param array $params Form params of the request to send to the Hail API.
     *
     * @return array Reply from Hail
     */
    public function get($uri, $params = null)
    {
        $options = [];
        $http = $this->getHTTPClient();
        $options['headers'] = [
            "Authorization" => "Bearer " . $this->getAccessToken()
        ];

        //Pass the body if needed
        if ($params) {
            $options['form_params'] = $params;
        }

        // Request
        try {
            $response = $http->request('GET', $uri, $options);
            $responseBody = $response->getBody();
            $responseArr = json_decode($responseBody, true);
        } catch (\Exception  $exception) {
            $this->handleException($exception);
            //Send empty array so the app doesnt crash
            $responseArr = [];
        }

        return $responseArr;
    }

    /**
     * Get one Hail object from the API
     *
     * @param mixed $hail_object Object to retrieve
     *
     * @return array Reply from Hail
     */
    public function getOne($hail_object)
    {
        $uri = $hail_object::$object_endpoint . '/' . $hail_object->HailID;

        return $this->get($uri);
    }

    public function fetchAccessToken($redirect_code)
    {
        $http = $this->getHTTPClient();
        $post_data = [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'authorization_code',
            'code' => $redirect_code,
            'redirect_uri' => $this->getRedirectURL(),
        ];
        // Request access token
        try {
            $response = $http->request('POST', 'oauth/access_token', [
                'form_params' => $post_data
            ]);

            $responseBody = $response->getBody();
            $responseArr = json_decode($responseBody, true);

            //Set new data into the config and update the current instance
            $this->setAccessToken($responseArr['access_token']);
            $this->setAccessTokenExpire($responseArr['expires_in']);
            $this->setRefreshToken($responseArr['refresh_token']);
        } catch (\Exception  $exception) {
            $this->handleException($exception);
        }
    }

    public function getHTTPClient()
    {
        return new HTTPClient(["base_uri" => $this->getApiBaseURL()]);
    }

    public function getApiBaseURL()
    {
        return Config::inst()->get(self::class, 'BaseApiUrl');
    }

    public function getRedirectURL()
    {
        return Director::absoluteURL('HailCallbackController', true);
    }

    public function setAccessTokenExpire($access_token_expire)
    {
        //Store expiry date as unix timestamp (now + expires in)
        $access_token_expire = time() + $access_token_expire;
        $this->access_token_expire = $access_token_expire;
        $config = SiteConfig::current_site_config();
        $config->HailAccessTokenExpire = $access_token_expire;
        $config->write();
    }

    public function setRefreshToken($refresh_token)
    {
        $config = SiteConfig::current_site_config();
        $config->HailRefreshToken = $refresh_token;
        $this->refresh_token = $refresh_token;
        $config->write();
    }

    public function handleException($exception)
    {
        //Log the error
        Injector::inst()->get(LoggerInterface::class)->debug($exception->getMessage());
        $request = Injector::inst()->get(HTTPRequest::class);
        $request->getSession()->set('notice', true);
        $request->getSession()->set('noticeType', 'bad');
        $message = $exception->getMessage();
        if ($exception->hasResponse()) {
            $response = json_decode($exception->getResponse()->getBody(), true);
            if (isset($response['error']) && isset($response['error']['message'])) {
                $message = $response['error']['message'];
            }
        }
        $request->getSession()->set('noticeText', $message);
    }

    public function getAuthorizationURL()
    {
        $url = Config::inst()->get(self::class, 'AuthorizationUrl');
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->getRedirectURL(),
            'response_type' => "code",
            'scope' => $this->scopes,
        ];
        return $url . "?" . http_build_query($params);
    }

    public function setUserID()
    {
        $response = $this->get("me");
        $config = SiteConfig::current_site_config();
        $config->HailUserID = $response['id'];
        $this->user_id = $response['id'];
        $config->write();
    }


    public function getAccessToken()
    {
        //Check if AccessToken needs to be refreshed
        $now = time();
        $time = time();
        $difference = $this->access_token_expire - $time;
        if ($difference < strtotime('15 minutes', 0)) {
            $this->refreshAccessToken();
        }

        return $this->access_token;
    }

    public function setAccessToken($access_token)
    {
        $this->access_token = $access_token;
        $config = SiteConfig::current_site_config();
        $config->HailAccessToken = $access_token;
        $config->write();
    }

    public function refreshAccessToken()
    {
        $http = $this->getHTTPClient();
        $post_data = [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refresh_token,
        ];

        // Refresh access token
        try {
            $response = $http->request('POST', 'oauth/access_token', [
                'form_params' => $post_data
            ]);

            $responseBody = $response->getBody();
            $responseArr = json_decode($responseBody, true);

            //Set new data into the config and update the current instance
            $this->setAccessToken($responseArr['access_token']);
            $this->setAccessTokenExpire($responseArr['expires_in']);
            $this->setRefreshToken($responseArr['refresh_token']);
        } catch (\Exception  $exception) {
            $this->handleException($exception);
        }
    }

    public function isAuthorised()
    {
        return $this->access_token_expire && $this->access_token && $this->refresh_token;
    }

    public function isReadyToAuthorised()
    {
        return $this->client_id && $this->client_secret;
    }

    public function getAvailableOrganisations($as_simple_array = false)
    {
        $organisations = $this->get('users/' . $this->user_id . '/organisations');
        //If simple array is true, we send back an array with [id] => [name] instead of the full list
        if ($as_simple_array) {
            $temp = [];
            foreach ($organisations as $org) {
                $temp[$org['id']] = $org['name'];
            }
            $organisations = $temp;
        }
        asort($organisations);
        return $organisations;
    }

    public function getAvailablePrivateTags($organisations = null, $as_simple_array = false)
    {
        $orgs_ids = $organisations ? array_keys($organisations) : json_decode($this->orgs_ids);
        if (!$orgs_ids) {
            //No organisations configured
            $this->handleException("You need at least 1 Hail Organisation configured to be able to fetch private tags");
            return false;
        }
        $tag_list = [];
        foreach ($orgs_ids as $org_id) {
            //Get Org Name
            if ($organisations) {
                $org_name = isset($organisations[$org_id]) ? $organisations[$org_id] : '';
            } else {
                $org = DataObject::get_one(Organisation::class, ['HailID' => $org_id]);
                $org_name = $org ? $org->Title : "";
            }

            $results = $this->get('organisations/' . $org_id . '/private-tags');
            //If simple array is true, we send back an array with [id] => [name] instead of the full list
            if ($as_simple_array) {
                foreach ($results as $result) {
                    $tag_title = $result['name'];
                    //Add organisation name on tag title if more than 1 org
                    if (count($orgs_ids) > 1) {
                        $tag_title = $org_name . " - " . $tag_title;
                    }
                    $tag_list[$result['id']] = $tag_title;
                }
            } else {
                $tag_list = array_merge($results, $tag_list);
            }
        }

        asort($tag_list);
        return $tag_list;
    }

    public function getAvailablePublicTags($organisations = null, $as_simple_array = false)
    {
        $orgs_ids = $organisations ? array_keys($organisations) : json_decode($this->orgs_ids);
        if (!$orgs_ids) {
            //No organisations configured
            $this->handleException("You need at least 1 Hail Organisation configured to be able to fetch private tags");
            return false;
        }
        $tag_list = [];
        foreach ($orgs_ids as $org_id) {
            //Get Org Name
            if ($organisations) {
                $org_name = isset($organisations[$org_id]) ? $organisations[$org_id] : '';
            } else {
                $org = DataObject::get_one(Organisation::class, ['HailID' => $org_id]);
                $org_name = $org ? $org->Title : "";
            }

            $results = $this->get('organisations/' . $org_id . '/tags');
            //If simple array is true, we send back an array with [id] => [name] instead of the full list
            if ($as_simple_array) {
                foreach ($results as $result) {
                    $tag_title = $result['name'];
                    //Add organisation name on tag title if more than 1 org
                    if (count($orgs_ids) > 1) {
                        $tag_title = $org_name . " - " . $tag_title;
                    }
                    $tag_list[$result['id']] = $tag_title;
                }
            } else {
                $tag_list = array_merge($results, $tag_list);
            }
        }

        asort($tag_list);
        return $tag_list;
    }

    /**
     * Get the refresh rate in seconds for Hail Objects. Hail Object that have
     * not been retrieve for longer than the refresh rate, should be fetch
     * again.
     *
     * @return int
     */
    public static function getRefreshRate()
    {
        return Config::inst()->get(self::class, 'RefreshRate');
    }

    /**
     * Retrieve a list of images for a given article.
     *
     * @param string $id ID of the article in Hail
     * @return array
     */
    public function getImagesByArticles($id)
    {
        $uri = Article::$object_endpoint . '/' . $id . '/' . Image::$object_endpoint;
        return $this->get($uri);
    }

    /**
     * Retrieve a list of videos for a given article.
     *
     * @param string $id ID of the article in Hail
     * @return array
     */
    public function getVideosByArticles($id)
    {
        $uri = Article::$object_endpoint . '/' . $id . '/' . Video::$object_endpoint;
        return $this->get($uri);
    }
}