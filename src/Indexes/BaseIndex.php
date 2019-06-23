<?php


namespace Firesphere\SolrSearch\Indexes;

use Firesphere\SolrSearch\Helpers\Synonyms;
use Firesphere\SolrSearch\Interfaces\ConfigStore;
use Firesphere\SolrSearch\Queries\BaseQuery;
use Firesphere\SolrSearch\Results\SearchResult;
use Firesphere\SolrSearch\Services\SchemaService;
use Firesphere\SolrSearch\Services\SolrCoreService;
use Firesphere\SolrSearch\Traits\BaseIndexTrait;
use Firesphere\SolrSearch\Traits\GetterSetterTrait;
use LogicException;
use Minimalcode\Search\Criteria;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Deprecation;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\ArrayData;
use Solarium\Core\Client\Adapter\Guzzle;
use Solarium\Core\Client\Client;
use Solarium\Core\Query\Helper;
use Solarium\QueryType\Select\Query\Query;

/**
 * Class BaseIndex
 * @package Firesphere\SolrSearch\Indexes
 */
abstract class BaseIndex
{
    use Extensible;
    use Configurable;
    use GetterSetterTrait;
    use BaseIndexTrait;

    private static $fieldTypes = [
        'FulltextFields',
        'SortFields',
        'FilterFields',
        'BoostedFields',
        'CopyFields',
        'DefaultField',
        'FacetFields',
    ];
    /**
     * @var Client
     */
    protected $client;
    /**
     * @var array
     */
    protected $fulltextFields = [];
    /**
     * @var array
     */
    protected $filterFields = [];
    /**
     * @var array
     */
    protected $sortFields = [];
    /**
     * @var array
     */
    protected $copyFields = [
        '_text' => [
            '*'
        ],
    ];
    /**
     * @var string
     */
    protected $defaultField = '_text';
    /**
     * @var SchemaService
     */
    protected $schemaService;

    /**
     * BaseIndex constructor.
     */
    public function __construct()
    {
        // Set up the client
        $config = Config::inst()->get(SolrCoreService::class, 'config');
        $config['endpoint'] = $this->getConfig($config['endpoint']);
        $this->client = new Client($config);
        $this->client->setAdapter(new Guzzle());

        // Set up the schema service, only used in the generation of the schema
        $schemaService = Injector::inst()->get(SchemaService::class, false);
        $schemaService->setIndex($this);
        $schemaService->setStore(Director::isDev());
        $this->schemaService = $schemaService;

        $this->extend('onBeforeInit');
        $this->init();
        $this->extend('onAfterInit');
    }

    /**
     * Build a full config for all given endpoints
     * This is to add the current index to e.g. an index or select
     * @param array $endpoints
     * @return array
     */
    public function getConfig($endpoints): array
    {
        foreach ($endpoints as $host => $endpoint) {
            $endpoints[$host]['core'] = $this->getIndexName();
        }

        return $endpoints;
    }

    /**
     * @return string
     */
    abstract public function getIndexName();

    /**
     * Required to initialise the fields.
     * It's loaded in to the non-static properties for backward compatibility with FTS
     * Also, it's a tad easier to use this way, loading the other way around would be very
     * memory intensive, as updating the config for each item is not efficient
     */
    public function init()
    {
        if (!self::config()->get($this->getIndexName())) {
            Deprecation::notice('5.0', 'Please set an index name');

            return;
        }

        // If the old init method is found, skip the config based init
        if (count($this->getClasses())) {
            return;
        }

        $config = self::config()->get($this->getIndexName());

        if (!array_key_exists('Classes', $config)) {
            throw new LogicException('No classes to index found!');
        }

        $this->setClasses($config['Classes']);

        // For backward compatibility, copy the config to the protected values
        // Saves doubling up further down the line
        foreach (self::$fieldTypes as $type) {
            if (array_key_exists($type, $config)) {
                $method = 'set' . $type;
                $this->$method($config[$type]);
            }
        }
    }

    /**
     * Default returns a SearchResult. It can return an ArrayData if FTS Compat is enabled
     *
     * @param BaseQuery $query
     * @return SearchResult|ArrayData|mixed
     */
    public function doSearch(BaseQuery $query)
    {
        $this->extend('onBeforeSearch', $query);
        $clientQuery = $this->client->createSelect();
        // Build the actual query parameters
        $queryArray = $this->buildSolrQuery($query, $clientQuery);
        // Build class filtering
        $this->buildClassFilter($query, $clientQuery);
        // Limit the results based on viewability
        $this->buildViewFilter($clientQuery);
        // Add filters
        $this->buildFilters($query, $clientQuery);
        // And excludes
        $this->buildExcludes($query, $clientQuery);
        // Add spellchecking
        if ($query->isSpellcheck()) {
            $this->buildSpellcheck($clientQuery, $queryArray);
        }
        // Add boosting
        $queryArray = $this->buildBoosts($query, $queryArray);
        // Add highlighting
        $clientQuery->getHighlighting()->setFields($query->getHighlight());

        // Setup the facets
        $this->buildFacets($query, $clientQuery);

        // Set the start
        $clientQuery->setStart($query->getStart());
        // Double the rows in case something has been deleted, but not from Solr
        $clientQuery->setRows($query->getRows() * 2);
        // Filter out the fields we want to see if they're set
        if (count($query->getFields())) {
            $clientQuery->setFields($query->getFields());
        }

        $q = implode(' ', $queryArray);
        $clientQuery->setQuery($q);

        $result = $this->client->select($clientQuery);

        // Handle the after search first. This gets a raw search result
        $this->extend('onAfterSearch', $result);
        $searchResult = new SearchResult($result, $query);

        // And then handle the search results, which is a useable object for SilverStripe
        $this->extend('updateSearchResults', $searchResult);

        return $searchResult;
    }

    /**
     * @param BaseQuery $query
     * @param Query $clientQuery
     * @return array
     */
    protected function buildSolrQuery(BaseQuery $query, Query $clientQuery): array
    {
        $helper = $clientQuery->getHelper();

        $searchQuery = [];
        $terms = $query->getTerms();
        foreach ($terms as $search) {
            $term = $search['text'];
            $term = $this->escapeSearch($term, $helper);
            // We can add the same term multiple times with different boosts
            // Not ideal, but it might happen, so let's add the term itself only once
            if (!in_array($term, $searchQuery, true)) {
                $searchQuery[] = $term;
            }
            // If boosting is set, add the fields to boost
            if ($search['boost'] > 1) {
                $searchQuery = $this->buildQueryBoost($search, $term, $searchQuery);
            }
        }

        return $searchQuery;
    }

    /**
     * @param string $searchTerm
     * @param Helper $helper
     * @return string
     */
    public function escapeSearch($searchTerm, Helper $helper): string
    {
        $term = [];
        // Escape special characters where needed. Except for quoted parts, those should be phrased
        preg_match_all('/"[^"]*"|\S+/', $searchTerm, $parts);
        foreach ($parts[0] as $part) {
            // As we split the parts, everything with two quotes is a phrase
            if (substr_count($part, '"') === 2) {
                $term[] = $helper->escapePhrase($part);
            } else {
                $term[] = $helper->escapeTerm($part);
            }
        }

        return implode(' ', $term);
    }

    /**
     * @param $search
     * @param string $term
     * @param array $searchQuery
     * @return array
     */
    protected function buildQueryBoost($search, string $term, array $searchQuery): array
    {
        foreach ($search['fields'] as $boostField) {
            $criteria = Criteria::where($boostField)
                ->is($term)
                ->boost($search['boost']);
            $searchQuery[] = $criteria->getQuery();
        }

        return $searchQuery;
    }

    /**
     * Add filtered queries based on class hierarchy
     * We only need the class itself, since the hierarchy will take care of the rest
     * @param BaseQuery $query
     * @param Query $clientQuery
     * @return Query
     */
    protected function buildClassFilter(BaseQuery $query, $clientQuery): Query
    {
        if (count($query->getClasses())) {
            $classes = $query->getClasses();
            $criteria = Criteria::where('ClassHierarchy')->in($classes);
            $clientQuery->createFilterQuery('classes')
                ->setQuery($criteria->getQuery());
        }

        return $clientQuery;
    }

    /**
     * @param Query $clientQuery
     */
    protected function buildViewFilter(Query $clientQuery): void
    {
        // Filter by what the user is allowed to see
        $viewIDs = ['1-null']; // null is always an option as that means publicly visible
        $currentUser = Security::getCurrentUser();
        if ($currentUser && $currentUser->exists()) {
            $viewIDs[] = '1-' . $currentUser->ID;
        }
        /** Add canView criteria. These are based on {@link DataObjectExtension::ViewStatus()} */
        $query = Criteria::where('ViewStatus')->in($viewIDs);

        $clientQuery->createFilterQuery('ViewStatus')
            ->setQuery($query->getQuery());
    }

    /**
     * @param BaseQuery $query
     * @param Query $clientQuery
     * @return Query
     */
    protected function buildFilters(BaseQuery $query, Query $clientQuery): Query
    {
        $filters = $query->getFilter();
        foreach ($filters as $field => $value) {
            $value = is_array($value) ? $value : [$value];
            $criteria = Criteria::where($field)->in($value);
            $clientQuery->createFilterQuery($field)
                ->setQuery($criteria->getQuery());
        }

        return $clientQuery;
    }

    /**
     * @param BaseQuery $query
     * @param Query $clientQuery
     * @return Query
     */
    protected function buildExcludes(BaseQuery $query, Query $clientQuery): Query
    {
        $filters = $query->getExclude();
        foreach ($filters as $field => $value) {
            $value = is_array($value) ? $value : [$value];
            $criteria = Criteria::where($field)
                ->is($value)
                ->not();
            $clientQuery->createFilterQuery($field)
                ->setQuery($criteria->getQuery());
        }

        return $clientQuery;
    }

    /**
     * @param Query $clientQuery
     * @param array $queryArray
     */
    protected function buildSpellcheck(Query $clientQuery, array $queryArray): void
    {
        // Assuming the first term is the term entered
        $queryString = implode(' ', $queryArray);
        // Arbitrarily limit to 5 if the config isn't set
        $count = static::config()->get('spellcheckCount') ?: 5;
        $spellcheck = $clientQuery->getSpellcheck();
        $spellcheck->setQuery($queryString);
        $spellcheck->setCount($count);
        $spellcheck->setBuild(true);
        $spellcheck->setCollate(true);
        $spellcheck->setExtendedResults(true);
        $spellcheck->setCollateExtendedResults(true);
    }

    /**
     * Add the index-time boosting to the query
     * @param BaseQuery $query
     * @param array $queryArray
     * @return array
     */
    protected function buildBoosts(BaseQuery $query, $queryArray): array
    {
        $boosts = $query->getBoostedFields();
        foreach ($boosts as $field => $boost) {
            foreach ($query->getTerms() as $term) {
                $booster = Criteria::where($field)
                    ->is($term)
                    ->boost($boost);
                $queryArray[] = $booster->getQuery();
            }
        }

        return $queryArray;
    }

    /**
     * @param BaseQuery $query
     * @param Query $clientQuery
     */
    protected function buildFacets(BaseQuery $query, Query $clientQuery): void
    {
        $facets = $clientQuery->getFacetSet();
        foreach ($query->getFacetFields() as $field => $config) {
            $facets->createFacetField($config['Title'])->setField($config['Field']);
        }
        $facets->setMinCount($query->getFacetsMinCount());
    }

    /**
     * Upload config for this index to the given store
     *
     * @param ConfigStore $store
     */
    public function uploadConfig(ConfigStore $store): void
    {
        // @todo use types/schema/elevate rendering
        // Upload the config files for this index
        // Create a default schema which we can manage later
        $schema = (string)$this->schemaService->generateSchema();
        $store->uploadString(
            $this->getIndexName(),
            'schema.xml',
            $schema
        );


        $synonyms = $this->getSynonyms();

        // Upload synonyms
        $store->uploadString(
            $this->getIndexName(),
            'synonyms.txt',
            $synonyms
        );

        // Upload additional files
        foreach (glob($this->schemaService->getExtrasPath() . '/*') as $file) {
            if (is_file($file)) {
                $store->uploadFile($this->getIndexName(), $file);
            }
        }
    }

    /**
     * Add synonyms. Public to be extendable
     * @param bool $eng Include UK to US synonyms
     * @return string
     */
    public function getSynonyms($eng = true): string
    {
        $engSynonyms = '';
        if ($eng) {
            $engSynonyms = Synonyms::getSynonymsAsString();
        }

        return $engSynonyms . SiteConfig::current_site_config()->getField('SearchSynonyms');
    }

    /**
     * @return array
     */
    public function getFieldsForIndexing(): array
    {
        return array_unique(
            array_merge(
                $this->getFulltextFields(),
                $this->getSortFields(),
                $this->getFilterFields()
            )
        );
    }
}
