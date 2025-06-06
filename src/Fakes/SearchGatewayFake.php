<?php

namespace Madmatt\Funnelback\Fakes;

use Madmatt\Funnelback\SearchGateway;

class SearchGatewayFake extends SearchGateway
{
    private ?array $fakeResults = null;

    public function __construct()
    {
        // no-op - no need to create Guzzle client or verify that environment variables exist
    }
    
    public function getResults(string $query, string $query_and, string $query_phrase, string $query_not, string $meta_t, string $meta_f_sand, string $meta_c, int $start, int $limit, string $sort): ?array
    {
        return $this->fakeResults;
    }

    /**
     * @param array $results The complete fake result set to return including all surrounding array structure
     * @return $this
     */
    public function setReturnedResults(array $results): self
    {
        $this->fakeResults = $results;

        return $this;
    }
}
