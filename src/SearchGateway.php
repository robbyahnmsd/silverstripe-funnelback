<?php

namespace Madmatt\Funnelback;

use Exception;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

use SilverStripe\Dev\Debug;

class SearchGateway
{
    use Configurable;
    use Injectable;

    private static $dependencies = [
        'logger' => '%$' . LoggerInterface::class,
    ];

    private Client $client;

    protected LoggerInterface $logger;

    private string $api_url;

    private string $api_username;

    private string $api_password;

    private string $api_collection;

    public function __construct()
    {
        $this->api_url = Environment::getEnv('SS_FUNNELBACK_URL');
        $this->api_username = Environment::getEnv('SS_FUNNELBACK_USERNAME');
        $this->api_password = Environment::getEnv('SS_FUNNELBACK_PASSWORD');
        $this->api_collection = Environment::getEnv('SS_FUNNELBACK_COLLECTION');

        if ($this->verifyEnvironmentVariables()) {
            $this->client = new Client([
                'base_uri' => $this->api_url,
            ]);
        }
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Should output an array as $decoded['response']['resultPacket']
     * if api request worked, otherwise @throws Exception
     */
    public function getResults(string $query, string $query_and, string $query_phrase, string $query_not, string $meta_t, string $meta_f_sand, string $meta_c, int $start, int $limit, string $sort): ?array
    {       
        if (!$this->client) {
            $message = SearchGateway::class. '::$client is not initialized, likely env vars are not configured
            correctly.';

            $this->logger->notice($message);
            throw new Exception($message);
        }

        try {
            $requestQuery = [
                'collection' => $this->api_collection,
                'query' => $query,
                'query_and' => $query_and,
                'query_phrase' => $query_phrase,
                'query_not' => $query_not,
                'meta_t' => $meta_t,
                'meta_f_sand' => $meta_f_sand,
                'meta_c' => $meta_c,
                'start_rank' => $start,
                'num_ranks' => $limit,
                'sort' => $sort,
                'contextual_navigation' => 1,           // I thinks this is right one        
                // 'contextual-navigation' => 1,
            ];
            
            $response = $this->client->request('GET', '/s/search.json', [
                'auth' => [$this->api_username, $this->api_password],
                'query' => $requestQuery,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() != 200) {
                $message = sprintf(
                    "Invalid Funnelback response. Code: %d, Response body: %s, Request body: %s",
                    $response->getStatusCode(),
                    $response->getBody(),
                    var_export($requestQuery, true)
                );

                $this->logger->notice($message);
                throw new Exception($message);
            }
           
            $body = $response->getBody();
            $decoded = json_decode($body, true);

            // type
            // Debug::show($decoded['response']['resultPacket']['contextualNavigation']['categories']);

            // Structure
            // array(
            //     0 => array(
            //         "name" => "type",
            //         "more" => 0,
            //         "moreLink" => null,
            //         "fewerLink" => null,
            //         "clusters" => array(
            //             0 => array(
            //                 "href"  => "?clicked_fluster=low+income&query=%60low+income%60&num_ranks=10&collection=msd-workandincome-web-new&sort=Default&contextual_navigation=1&cluster0=income",
            //                 "count" => 5,
            //                 "label" => "Low...",
            //                 "query" => "low income",
            //             ),
            //             1 => array(
            //                 "href"  => "?clicked_fluster=your+income&query=%60your+Income%60&num_ranks=10&collection=msd-workandincome-web-new&sort=Default&contextual_navigation=1&cluster0=income",
            //                 "count" => 3,
            //                 "label" => "Your...",
            //                 "query" => "your Income",
            //             ),
            //         ),
            //     ),
            //     1 => array(
            //         "name" => "topic",
            //         "more" => 0,
            //         "moreLink" => null,
            //         "fewerLink" => null,
            //         "clusters" => array(
            //             0 => array(
            //                 "href"  => "?clicked_fluster=work+and+income&query=%60Work+and+Income%60&num_ranks=10&collection=msd-workandincome-web-new&sort=Default&contextual_navigation=1&cluster0=income",
            //                 "count" => 99,
            //                 "label" => "Work and...",
            //                 "query" => "Work and Income",
            //             ),
            //         ),
            //     ),
            // );            

            // topic
            // var_dump($decoded['response']['resultPacket']['contextualNavigation']);

            if ($decoded == null) {
                $this->logger->notice($message = "Invalid JSON response: ". $body);
                throw new Exception($message);
            }

            return $decoded['response']['resultPacket'] ?? [];
        } catch (Exception $e) {
            $this->logger->notice($message = "Exception: " . $e->getMessage());
            throw new Exception($message);
        }
    }

    protected function verifyEnvironmentVariables()
    {
        if (!$this->api_url) {
            user_error('Environment variable SS_FUNNELBACK_URL is not supplied', E_USER_ERROR);
            return false;
        }

        if (!$this->api_username) {
            user_error('Environment variable SS_FUNNELBACK_USERNAME is not supplied', E_USER_ERROR);
            return false;
        }

        if (!$this->api_password) {
            user_error('Environment variable SS_FUNNELBACK_PASSWORD is not supplied', E_USER_ERROR);
            return false;
        }

        if (!$this->api_collection) {
            user_error('Environment variable SS_FUNNELBACK_COLLECTION is not supplied', E_USER_ERROR);
            return false;
        }

        return true;
    }
    
    // https://msd-uat-search.squiz.cloud/s/suggest.json?collection=msd-workandincome-web&fmt=json++&alpha=0.5&profile=_default_preview&show=10&sort=0&partial_query=income
    public function getSuggestions(string $partialQuery): array
    {
        if (!$this->client) {
            $message = self::class . '::$client not initialized — check Funnelback env vars.';
            $this->logger->notice($message);
            throw new Exception($message);
        }

        try {
            $response = $this->client->request('GET', '/s/suggest.json', [
                'auth' => [$this->api_username, $this->api_password],
                'query' => [
                    'collection' => $this->api_collection,
                    'profile' => '_default',
                    'fmt' => 'json++',
                    'partial_query' => $partialQuery,
                    'show' => 10,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new Exception("Invalid Funnelback response: " . $response->getBody());
            }

            $jsonSuggestionbody = json_decode($response->getBody(), true);            

            if (!isset($jsonSuggestionbody)) {
                return [];
            }

            return $jsonSuggestionbody;
        } catch (Exception $e) {
            $this->logger->notice("Suggestion error: " . $e->getMessage());
            return [];
        }
    }

}
