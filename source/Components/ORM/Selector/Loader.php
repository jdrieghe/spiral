<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\ORM\Selector;

use Spiral\Components\DBAL\Database;
use Spiral\Components\DBAL\QueryResult;
use Spiral\Components\ORM\ActiveRecord;
use Spiral\Components\ORM\ORM;
use Spiral\Components\ORM\ORMException;
use Spiral\Components\ORM\Selector;

abstract class Loader
{
    /**
     * Relation type is required to correctly resolve foreign model.
     */
    const RELATION_TYPE = null;

    /**
     * Default load method (inload or postload).
     */
    const LOAD_METHOD = null;

    /**
     * Loaders associated with relation type.
     *
     * @var array
     */
    protected static $loaderClasses = [
        ActiveRecord::HAS_ONE    => 'Spiral\Components\ORM\Selector\Loaders\HasOneLoader',
        ActiveRecord::HAS_MANY   => 'Spiral\Components\ORM\Selector\Loaders\HasManyLoader',
        ActiveRecord::BELONGS_TO => 'Spiral\Components\ORM\Selector\Loaders\BelongsToLoader'
    ];

    /**
     * Container related to parent loader.
     *
     * @var string
     */
    protected $container = '';

    /**
     * Relation definition options.
     *
     * @var array
     */
    protected $relationDefinition = [];

    /**
     * ORM component is required to fetch all required model schemas.
     *
     * @invisible
     * @var ORM
     */
    protected $orm = null;

    /**
     * Related model schema.
     *
     * @invisible
     * @var array
     */
    protected $schema = [];

    /**
     * Parent loader.
     *
     * @invisible
     * @var Loader|null
     */
    protected $parent = null;

    /**
     * Loader options usually used while declaring joins and etc.
     *
     * @var array
     */
    protected $options = [
        'method' => null,
        'alias'  => null
    ];

    /**
     * List of keys has to be stored as data references. This set of keys is required by inner loader(s)
     * to quickly compile nested data.
     *
     * @var array
     */
    protected $referenceKeys = [];

    protected $columns = [];

    protected $offset = 0;

    protected $countColumns = 0;

    protected $references = [];

    protected $aggregatedReferences = [];

    protected $duplicates = [];

    protected $result = [];

    /**
     * Sub loaders.
     *
     * @var Loader[]
     */
    protected $loaders = [];

    public function __construct(
        $container,
        array $relationDefinition = [],
        ORM $orm,
        Loader $parent = null
    )
    {
        $this->container = $container;
        $this->relationDefinition = $relationDefinition;
        $this->orm = $orm;

        $this->schema = $orm->getSchema($relationDefinition[static::RELATION_TYPE]);
        $this->parent = $parent;

        //Compiling options
        $this->options['method'] = static::LOAD_METHOD;

        if ($this->parent instanceof Selector\Loaders\RootLoader)
        {
            $this->options['alias'] = $container;
        }
        else
        {
            $this->options['alias'] = $this->parent->getAlias() . '_' . $container;
        }

        $this->columns = array_keys($this->schema[ORM::E_COLUMNS]);
        $this->countColumns = count($this->schema[ORM::E_COLUMNS]);
    }

    public function dbalDatabase()
    {
        return $this->orm->getDBAL()->db($this->schema[ORM::E_DB]);
    }

    public function getAlias()
    {
        return $this->options['alias'];
    }

    public function getReferenceName(array $data)
    {
        $definition = $this->relationDefinition;

        if (!isset($data[$definition[ActiveRecord::OUTER_KEY]]))
        {
            return null;
        }

        //Fairly simple logic
        return $definition[ActiveRecord::INNER_KEY] . '::' . $data[$definition[ActiveRecord::OUTER_KEY]];
    }

    /**
     * @param string $relation
     * @param array  $options
     * @return Loader
     */
    public function addLoader($relation, array $options = [])
    {
        if (($position = strpos($relation, '.')) !== false)
        {
            $parentRelation = substr($relation, 0, $position);

            //Recursively
            return $this->getLoader($parentRelation)->addLoader(
                substr($relation, $position + 1),
                $options
            );
        }

        if (!isset($this->schema[ORM::E_RELATIONS][$relation]))
        {
            $container = $this->container ?: $this->schema[ORM::E_ROLE_NAME];

            throw new ORMException(
                "Undefined relation '{$relation}' under '{$container}'."
            );
        }

        if (isset($this->loaders[$relation]))
        {
            $this->loaders[$relation]->setOptions($options);

            return $this->loaders[$relation];
        }

        $relationOptions = $this->schema[ORM::E_RELATIONS][$relation];

        //Adding loader
        $loader = self::$loaderClasses[$relationOptions[ORM::R_TYPE]];

        /**
         * @var Loader $loader
         */
        $loader = new $loader(
            $relation,
            $relationOptions[ORM::R_DEFINITION],
            $this->orm,
            $this
        );

        $loader->setOptions($options);
        $this->loaders[$relation] = $loader;

        if ($loader->getReferenceKey())
        {
            $this->referenceKeys[] = $loader->getReferenceKey();
            $this->referenceKeys = array_unique($this->referenceKeys);
        }

        return $loader;
    }

    /**
     * Simple alias for addLoader().
     *
     * @param string $relation
     * @return Loader
     */
    public function getLoader($relation)
    {
        return $this->addLoader($relation);
    }

    /**
     * Update loader options.
     *
     * @param array $options
     * @return static
     */
    public function setOptions(array $options = [])
    {
        $this->options = $options + $this->options;

        return $this;
    }

    /**
     * Reference key (from parent object) required to speed up data normalization.
     *
     * @return string
     */
    public function getReferenceKey()
    {
        //Fairly simple logic
        return $this->relationDefinition[ActiveRecord::INNER_KEY];
    }

    public function getTable()
    {
        return $this->schema[ORM::E_TABLE];
    }

    /**
     * @return Selector[]
     */
    public function getPostSelectors()
    {
        $selectors = [];
        foreach ($this->loaders as $loader)
        {
            if ($loader->options['method'] == Selector::POSTLOAD)
            {
                $selector = $loader->createSelector();

                if (!empty($selector))
                {
                    $selectors[] = $selector;
                }
            }
            else
            {
                $selectors = array_merge($selectors, $loader->getPostSelectors());
            }
        }

        return $selectors;
    }

    public function createSelector()
    {
        $selector = new Selector(
            $this->relationDefinition[static::RELATION_TYPE],
            $this->orm,
            [],
            $this
        );

        $this->offset = $selector->registerColumns(
            $this->getAlias(),
            array_keys($this->schema[ORM::E_COLUMNS])
        );

        foreach ($this->loaders as $loader)
        {
            $loader->clarifySelector($selector);
        }

        return $selector;
    }

    public function clarifySelector(Selector $selector)
    {
        if ($this->options['method'] != Selector::INLOAD)
        {
            return;
        }

        //Mounting columns
        $this->offset = $selector->registerColumns(
            $this->getAlias(),
            array_keys($this->schema[ORM::E_COLUMNS])
        );

        //Inload conditions and etc
        $this->clarifyQuery($selector);

        foreach ($this->loaders as $loader)
        {
            $loader->clarifySelector($selector);
        }
    }

    abstract protected function clarifyQuery(Selector $selector);

    /**
     * Parser provided query result to fetch model fields and resolve nested loaders.
     *
     * @param QueryResult $result
     * @param int         $rowsCount
     * @return array
     */
    public function parseResult(QueryResult $result, &$rowsCount)
    {
        foreach ($result as $row)
        {
            $this->parseRow($row);
            $rowsCount++;
        }

        return $this->result;
    }

    /**
     * Get built loader result. Result data can be altered by nested loaders (inload and postload),
     * so we have to run all post loaders before using this method result.
     *
     * @return array
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * Parse single result row, should fetch related model fields and run nested loader parsers.
     *
     * @param array $row
     * @return mixed
     */
    abstract public function parseRow(array $row);

    /**
     * Helper method used to fetch named fields from query result, will automatically calculate data
     * offset and resolve field aliases.
     *
     * @param array $row
     * @return array
     */
    protected function fetchData(array $row)
    {
        $row = array_slice($row, $this->offset, $this->countColumns);

        //Populating keys
        return array_combine($this->columns, $row);
    }

    public function getAggregatedKeys($key)
    {
        //TODO: DISABLE WHEN NOT REQUIRED

        if (!isset($this->aggregatedReferences[$key]))
        {
            return [];
        }

        return array_keys($this->aggregatedReferences[$key]);
    }

    public function registerNestedParent($container, $key, $value, &$data)
    {
        foreach ($this->aggregatedReferences[$key][$value] as &$subset)
        {
            if (!isset($subset[$container]))
            {
                $subset[$container] = &$data;
            }

            unset($subset);
        }
    }

    public function registerNested($referenceName, $container, array &$data, $multiple = false)
    {
        if (!isset($this->references[$referenceName]))
        {
            //Nothing to do
            return;
        }

        if ($multiple)
        {
            $this->references[$referenceName][$container][] = &$data;
        }
        else
        {
            if (!isset($this->references[$referenceName][$container]))
            {
                /**
                 * There is very tricky spot where you have to be careful (i spend 2 hours for debugging).
                 * If you will reassign references it will loose previous references and some sets of
                 * data will be broken.
                 */
                $this->references[$referenceName][$container] = &$data;
            }
        }
    }


    protected function checkDuplicate(array &$data)
    {
        if (isset($this->schema[ORM::E_PRIMARY_KEY]))
        {
            $primaryKey = $this->schema[ORM::E_PRIMARY_KEY];

            if (isset($this->duplicates[$data[$primaryKey]]))
            {
                //Duplicate is presented, let's reduplicate (will update reference)
                $data = $this->duplicates[$data[$primaryKey]];

                return true;
            }

            $this->duplicates[$data[$primaryKey]] = &$data;
        }
        else
        {
            /**
             * It is recommended to use primary keys in every model as it will speed up deduplication.
             */
            $serialization = serialize($data);
            if (isset($this->duplicates[$serialization]))
            {
                //Duplicate is presented, let's reduplicate
                $data = $this->duplicates[$serialization];

                //Duplicate is presented
                return true;
            }

            $this->duplicates[$serialization] = &$data;
        }

        return false;
    }

    protected function registerReferences(array &$data)
    {
        foreach ($this->referenceKeys as $key)
        {
            //Adding reference
            $this->references[$key . '::' . $data[$key]] = &$data;
            $this->aggregatedReferences[$key][$data[$key]][] = &$data;
        }
    }

    protected function parseNested(array $row)
    {
        foreach ($this->loaders as $loader)
        {
            if ($loader->options['method'] == Selector::INLOAD)
            {
                $loader->parseRow($row);
            }
        }
    }

    /**
     * Clean loader data.
     */
    public function clean()
    {
        $this->duplicates = [];
        $this->references = [];
        $this->aggregatedReferences = [];
        $this->result = [];

        foreach ($this->loaders as $loader)
        {
            $loader->clean();
        }
    }

    /**
     * Destruct loader.
     */
    public function __destruct()
    {
        $this->clean();
    }
}