<?php

namespace Madmatt\Funnelback;

use SilverStripe\Assets\File;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;
use SilverStripe\ORM\PaginatedList;

/**
 * Provides ability to integrate with FunnelBack search api
 */
class SearchService
{
    use Configurable;
    use Injectable;

    public const FILE_TYPE_HTML = 'html';

    public function search(string $keyword = "",  string $query_and= "", string $query_phrase = "", string $query_not = "", string $meta_t = "", string $meta_f_sand = "", string $meta_c = "", int $start = 0, int $limit = 10, string $sort = ""): ?PaginatedList
    {
        // Short circuit - if no keyword is entered, don't bother searching
        if (!$keyword) {
            return PaginatedList::create(ArrayList::create());
        }

        // Fetch results from the gateway and convert them into a standard Silverstripe ArrayList
        try {
            $gateway = SearchGateway::create();
            // $data = $gateway->getResults($keyword, $start, $limit, $sort);
            $data = $gateway->getResults($keyword, $query_and, $query_phrase, $query_not, $meta_t, $meta_f_sand, $meta_c, $start, $limit, $sort);

            if (!$data || !isset($data['results']) || !isset($data['resultsSummary'])) {
                return null;
            }

            $results = $data['results'];
            $contextualNavResults = $data['contextualNavigation']['categories'];

            $list = ArrayList::create();

            foreach ($results as $result) {
                $fileType = $result['fileType'];
                $title = $result['title'];

                // If the file is anything but HTML, then it's downloadable. Ensure we append the file type and file size to the end
                if ($fileType != self::FILE_TYPE_HTML) {
                    $file = $this->getFileFromURL($result['indexUrl']);
                    $title = $this->formatFileTitle(
                        $file ? $file->Title : 'File Not Found',
                        $fileType,
                        $result['fileSize']
                    );
                }

                $list->push([
                    'Link' => $result['liveUrl'],
                    'Title' => $title,
                    'Summary' => $result['summary'],
                    'FileType' => $fileType
                ]);
            }

            $list = new PaginatedList($list);
            $list->setPageStart($start);
            $list->setPageLength($limit);
            $list->setTotalItems($data['resultsSummary']['totalMatching']);
            $list->setLimitItems(false);

           
            $contextualNavList = $this->formatContextualNavigation($contextualNavResults, $keyword);
            $list->ContextualNavigation = $contextualNavList;

            return $list;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Format contextual navigation results into a SilverStripe ArrayList
     *
     * @param array $contextualNavResults
     * @return ArrayList
     */
    protected function formatContextualNavigation(array $contextualNavResults, string $keyword): ArrayList
    {
        $list = ArrayList::create();
        
        if (!empty($contextualNavResults)) {
            foreach ($contextualNavResults as $category) {
                $clusters = ArrayList::create();
                
                if (!empty($category['clusters'])) {
                    foreach ($category['clusters'] as $cluster) {
                        $highlightedQuery = $this->highlightKeywordInQuery($cluster['query'] ?? '', $keyword);
                      
                        $clusters->push(ArrayData::create([
                            'Label' => $cluster['label'] ?? '',
                            'Query' => $cluster['query'] ?? '',
                            'HighlightedQuery' => $highlightedQuery,
                            'Href' => $cluster['href'] ?? '',
                            'Count' => $cluster['count'] ?? 0,
                            'Keyword' => $keyword ?? '',
                        ]));
                    }
                }
                
                $firstUppercasename = $this->firstUppercase($category['name'] ?? '');

                $list->push(ArrayData::create([
                    'Name' => $category['name'] ?? '',
                    'FirstUppercaseName' => $firstUppercasename,
                    'Keyword' => $keyword ?? '',
                    'More' => $category['more'] ?? 0,
                    'MoreLink' => $category['moreLink'],
                    'FewerLink' => $category['fewerLink'],
                    'Clusters' => $clusters,
                ]));
            }
        }
        
        return $list;
    }


     /**
     * Highlight matching keywords in a query string by wrapping them with <strong> tags
     *
     * @param string $query The query string to highlight
     * @param string $keyword The keyword to highlight
     * @return string The query with highlighted keywords
     */
    protected function highlightKeywordInQuery(string $query, string $keyword): string
    {
        if (empty($keyword) || empty($query)) {
            return $query;
        }

        // Split keyword into individual words for better matching        
        $keywords = array_filter(explode(' ', trim($keyword)));
        
        foreach ($keywords as $word) {
            // Use case-insensitive replacement with word boundaries
            $pattern = '/\b(' . preg_quote($word, '/') . ')\b/i';
            $query = preg_replace($pattern, '<strong>$1</strong>', $query);
        }
        
        $query = ucfirst($query);

        return $query;
    }

    protected function firstUppercase(string $categoryName) {
        return ucfirst($categoryName);
    }


    /**
     * @param string $fileTitle The name of the file as provided by Funnelback (e.g. 'Service Specification')
     * @param string $fileType The file type as provided by Funnelback (e.g. 'pdf')
     * @param string $fileSize The file size  as provided by Funnelback in bytes (e.g. '1048576' for a 1 MB file)
     * @return string
     */
    protected function formatFileTitle(string $fileTitle, string $fileType, string $fileSize): string
    {
        return sprintf(
            "%s (%s %s)",
            trim($fileTitle),
            strtoupper($fileType),
            $this->formatFileSizeString($fileSize)
        );
    }

    /**
     * Format the filesize in bytes to the nearest highest unit (e.g. show the size in MB until we to a file that is
     * greater than 1GB in size).
     *
     * @param string $fileSizeBytes The file size in bytes as a integer string (e.g. '1048576')
     * @return string
     */
    protected function formatFileSizeString(string $fileSizeBytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $prefixIndex = floor(($fileSizeBytes ? log($fileSizeBytes) : 0) / log(1024));
        $prefixIndex = min($prefixIndex, count($units) - 1);

        $fileSizeBytes /= pow(1024, $prefixIndex);

        return round($fileSizeBytes, 0) . $units[$prefixIndex];
    }

    /**
     * The functions takes in a full url (including protocol) and tries to find a
     * matching file in the assets folder
     *
     *
     * @param string $url
     * @return File|null
     */
    private function getFileFromURL(string $url): ?File
    {
        $path = parse_url($url)['path'];
        //removes 'assets', since File::find does not expect it to be there
        $path = preg_replace('/' . ASSETS_DIR . '\//', '', $path, 1);

        return File::find($path);
    }

    public function getContextualLinks(string $type = '', string $topic = '', int $limit = 5): ?ArrayList
    {
        try {
            $gateway = SearchGateway::create();
            $results = $gateway->getContextualNavigation($type, $topic, $limit);

            $list = ArrayList::create();

            foreach ($results as $result) {
                $list->push(ArrayData::create([
                    'Title' => $result['title'] ?? 'test',
                    'URL' => $result['url'] ?? 'test',
                    'Summary' => $result['summary'] ?? 'test',
                ]));
            }

            return $list;
        } catch (\Exception $e) {
            return ArrayList::create(); // Empty list for safety
        }
    }
}
