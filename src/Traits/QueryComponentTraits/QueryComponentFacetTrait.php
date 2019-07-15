<?php


namespace Firesphere\SolrSearch\Traits;

use Firesphere\SolrSearch\Indexes\BaseIndex;
use Firesphere\SolrSearch\Queries\BaseQuery;
use Minimalcode\Search\Criteria;
use SilverStripe\Control\Controller;
use Solarium\QueryType\Select\Query\Query;

trait QueryComponentFacetTrait
{
    /**
     * @var BaseIndex
     */
    protected $index;
    /**
     * @var BaseQuery
     */
    protected $query;
    /**
     * @var Query
     */
    protected $clientQuery;

    /**
     * Add facets from the index
     */
    protected function buildFacets(): void
    {
        $facets = $this->clientQuery->getFacetSet();
        // Facets should be set from the index configuration
        foreach ($this->index->getFacetFields() as $class => $config) {
            $facets->createFacetField('facet-' . $config['Title'])->setField($config['Field']);
        }
        // Count however, comes from the query
        $facets->setMinCount($this->query->getFacetsMinCount());
    }

    /**
     * Add facet filters based on the current request
     */
    protected function buildFacetQuery()
    {
        $filterFacets = [];
        if (Controller::has_curr()) {
            $filterFacets = Controller::curr()->getRequest()->requestVars();
        }
        foreach ($this->index->getFacetFields() as $class => $config) {
            if (array_key_exists($config['Title'], $filterFacets)) {
                $filter = array_filter($filterFacets[$config['Title']], 'strlen');
                if (count($filter)) {
                    $criteria = Criteria::where($config['Field'])->in($filter);
                    $this->clientQuery
                        ->createFilterQuery('facet-' . $config['Title'])
                        ->setQuery($criteria->getQuery());
                }
            }
        }
    }
}
