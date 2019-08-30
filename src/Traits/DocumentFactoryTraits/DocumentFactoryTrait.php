<?php


namespace Firesphere\SolrSearch\Traits;

use Firesphere\SolrSearch\Factories\DocumentFactory;
use Firesphere\SolrSearch\Helpers\FieldResolver;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;

/**
 * Trait DocumentFactoryTrait
 * @package Firesphere\SolrSearch\Traits
 */
trait DocumentFactoryTrait
{
    /**
     * @var FieldResolver
     */
    protected $introspection;
    /**
     * @var null|ArrayList|DataList
     */
    protected $items;
    /**
     * @var string
     */
    protected $class;

    /**
     * @return string
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * @param string $class
     * @return DocumentFactory
     */
    public function setClass(string $class): self
    {
        $this->class = $class;

        return $this;
    }

    /**
     * @return FieldResolver
     */
    public function getIntrospection(): FieldResolver
    {
        return $this->introspection;
    }

    /**
     * @return ArrayList|DataList|null
     */
    public function getItems()
    {
        return $this->items;
    }

    /**
     * @param ArrayList|DataList|null $items
     * @return DocumentFactory
     */
    public function setItems($items): self
    {
        $this->items = $items;

        return $this;
    }
}
