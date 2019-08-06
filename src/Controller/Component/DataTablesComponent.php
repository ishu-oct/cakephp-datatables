<?php
namespace DataTables\Controller\Component;

use Cake\Controller\Component;
use Cake\ORM\TableRegistry;

/**
 * DataTables component
 */
class DataTablesComponent extends Component
{
    protected $_defaultConfig = [
        'start' => 0,
        'length' => 10,
        'order' => [],
        'conditionsOr' => [], // table-wide search conditions
        'conditionsAnd' => [], // column search conditions
        'matching' => [], // column search conditions for foreign tables
    ];

    protected $_viewVars = [
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'draw' => 0,
    ];

    protected $_isAjaxRequest = false;

    protected $_applyLimit = true;

    protected $_tableName = null;

    protected $_plugin = null;

    //TODO: Rewrite it
    private $queryFields = []; //To handle computed fields in conditions

    /**
     * Process query data of ajax request
     *
     */
    private function _processRequest($param = null)
    {
        // -- check whether it is an ajax call from data tables server-side plugin or a normal request
        $this->_isAjaxRequest = $this->request->is('ajax');

        // -- check whether it is an csv request
        if (isset($this->request->query['_csv_output']) && $this->request->query['_csv_output'] == true) {
            $this->_applyLimit = false;
        }

        if ($this->_applyLimit === true) {
            // -- add limit
            if (isset($this->request->query['length']) && !empty($this->request->query['length'])) {
                $this->config('length', $this->request->query['length']);
            }

            // -- add offset
            if (isset($this->request->query['start']) && !empty($this->request->query['start'])) {
                $this->config('start', (int)$this->request->query['start']);
            }
        }

        // -- add order
        if (isset($this->request->query['order']) && !empty($this->request->query['order'])) {
            $order = $this->config('order');
            foreach ($this->request->query['order'] as $item) {
                $order[$this->request->query['columns'][$item['column']]['name']] = $item['dir'];
            }
            $this->config('order', $order);
        }

        // -- add draw (an additional field of data tables plugin)
        if (isset($this->request->query['draw']) && !empty($this->request->query['draw'])) {
            $this->_viewVars['draw'] = (int)$this->request->query['draw'];
        }

        // -- don't support any search if columns data missing
        if (!isset($this->request->query['columns']) ||
            empty($this->request->query['columns'])) {
            return;
        }

        // -- check table search field
        $globalSearch = (isset($this->request->query['search']['value']) ?
            $this->request->query['search']['value'] : false);

        // -- add conditions for both table-wide and column search fields
        foreach ($this->request->query['columns'] as $column) {
            if (!empty($column['name'])) {
                if ($globalSearch && $column['searchable'] == 'true') {
                    $this->_addCondition($column['name'], $globalSearch, 'or', true);
                }
                $localSearch = $column['search']['value'];
                /* In some circumstances (no "table-search" row present), DataTables
                fills in all column searches with the global search. Compromise:
                Ignore local field if it matches global search. */
                if ((isset($localSearch) && strlen($localSearch) > 0) && ($localSearch !== $globalSearch)) {
                    if (isset($column['search']['regex']) && $column['search']['regex'] != 'false') {
                        /*  Hack regex field is used for wild card search or full search options.
                            If regex field is true then we have to use full search (column must equal to value)
                            otherwise we can perform wild card search (%LIKE%) */
                        $this->_addCondition($column['name'], $column['search']['value'], 'and', false);
                    } else {
                        $this->_addCondition($column['name'], $column['search']['value'], 'and', true);
                    }
                }
            }
        }
    }

    /**
     * Get data paths for CSV export
     *
     * @return array
     */
    public function getPaths()
    {
        $parser = [];
        foreach ($this->request->query['columns'] as $column) {
            if (!empty($column['name']) && !empty($column['data'])) {
                $parser[] = $column['data'];
            }
        }
        return $parser;
    }

    /**
     * Get data header for CSV export
     *
     * @return array
     */
    public function getHeader()
    {
        $header = [];
        foreach ($this->request->query['columns'] as $column) {
            if (!empty($column['name'])) {
                $column = str_replace('_matchingData.', '', $column['name']);
                $header[] = $column;
            }
        }
        return $header;
    }

    /**
     * Check if specific array key exists in multidimensional array
     * @param array $arr - An array with keys to check.
     * @param string $lookup - Value to check.
     * @return Returns path of the key on success or null on failure.
     */
    public function findPath($arr, $lookup)
    {
        if (array_key_exists($lookup, $arr)) {
            return [$lookup];
        } else {
            foreach ($arr as $key => $subarr) {
                if (is_array($subarr)) {
                    $ret = $this->findPath($subarr, $lookup);

                    if ($ret) {
                        $ret[] = $key;
                        return $ret;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Check if specific array key exists in multidimensional array
     * @param array $arr - An array with keys to check.
     * @param string $lookup - Value to check.
     * @return Returns dot notation path of the key on success or FALSE on failure.
     */
    public function getKeyPath($arr, $lookup)
    {
        $path = $this->findPath($arr, $lookup);
        if ($path === null) {
            return false;
        } else {
            return implode('.', array_reverse($path));
        }
    }

    /**
     * Find data
     *
     * @param string $tableName Name of the table
     * @param string $finder all,list.,
     * @param array $options query options
     * @return array|\Cake\ORM\Query
     */
    public function find($tableName, $finder = 'all', array $options = [])
    {
        // -- get table object
        $table = TableRegistry::get($tableName);
        $data = $table->find($finder, $options);
        return $this->process($data);
    }

    /**
     * Process queryObject
     *
     * @param \Cake\ORM\Query  $queryObject Query object to be processed
     * @param array $filterParams used for post request instead of ajax
     * @param bool $applyLimit boolean to set Limit
     * @return array|\Cake\ORM\Query
     */
    public function process($queryObject, $filterParams = null, $applyLimit = null)
    {
        $this->_setQueryFields($queryObject);

        //set table alias name
        $this->_tableName = $queryObject->repository()->alias();

        if ($filterParams !== null) {
            $this->request->query['columns'] = $filterParams['columns'];
            $this->request->query['search'] = $filterParams['search'];
            $this->_applyLimit = false;
        }

        if ($applyLimit !== null) {
            $this->_applyLimit = $applyLimit;
        }

        // -- get query options
        $this->_processRequest();

        // -- record count
        $this->_viewVars['recordsTotal'] = $queryObject->count();

        // -- filter result
        if (count($this->config('conditionsAnd')) > 0) {
            $queryObject->where($this->config('conditionsAnd'));
        }

        foreach ($this->config('matching') as $association => $where) {
            /*$associationPath = $this->getKeyPath($queryObject->contain(), $association);
            if ($associationPath !== false) {
                $queryObject->contain([
                    $associationPath => function ($q) use ($where) {
                        return $q->where($where);
                    },
                ]);
            } elseif (isset($queryObject->join()[$association])) {
                $queryObject->andWhere($where);
            } else {
                $queryObject->matching($association, function ($q) use ($where) {
                    return $q->where($where);
                });
            }*/
            $queryObject->where($where);
        }

        if (count($this->config('conditionsOr')) > 0) {
            $queryObject->andWhere(['or' => $this->config('conditionsOr')]);
        }

        //->bufferResults(true) Hack => when we add inner join cond the count will be returned from cache not actual count
        $this->_viewVars['recordsFiltered'] = $queryObject->bufferResults(true)->count();

        if ($this->_applyLimit === true) {
            // -- add limit
            $queryObject->limit($this->config('length'));
            $queryObject->offset($this->config('start'));
        }

        // -- sort
        $queryObject->order($this->config('order'));

        // -- set all view vars to view and serialize array
        $this->_setViewVars();

        return $queryObject;
    }

    private function _getController()
    {
        return $this->_registry->getController();
    }

    private function _setViewVars()
    {
        $this->_getController()->set($this->_viewVars);
        $this->_getController()->set('_serialize', array_keys($this->_viewVars));
        if (isset($this->request->query['_csv_output']) && $this->request->query['_csv_output'] == true) {
            // In case of CSV download, set csv headers and the fields to be inserted into csv file
            $this->_getController()->set('_header', $this->getHeader());
            $this->_getController()->set('_extract', $this->getPaths());
        }
    }

    private function _addCondition($column, $value, $type = 'and', $isWildcardSearch = true)
    {
        $columnObj = $this->_getColumnName($column);
        $condition = [];
        if ($isWildcardSearch == true) {
            $condition = [$this->queryObject->newExpr()->like($columnObj, "%$value%", 'string')];
        } else {
            if (is_null($value) || $value == 'null' || $value == 'NULL') {
                $condition = [$column . ' IS NULL'];
            } else {
                $condition[$column] = $value;
            }
        }
        
        if ($type === 'or') {
            $this->config('conditionsOr', $condition); // merges
            return;
        } else {
            $pieces = explode('.', $column);
            if (count($pieces) > 1) {
                list($association, $field) = $pieces;
                if ($this->_tableName == $association) {
                    $this->config('conditionsAnd', $condition); // merges
                } else {
                    $this->config('matching', [$association => $condition]); // merges
                }
            } else {
                $this->config('conditionsAnd', $condition); // merges
            }
        }
    }

    /**
     * Replace all computed columns
     * @param string $name
     * @return string
     */
    private function _getColumnName($name)
    {
        if (isset($this->queryFields[$name])) {
            return $this->queryFields[$name];
        } else {
            return $name;
        }
    }

    /**
     * Set the query object and select fields, so that it can be used in conditions
     * @param Query $queryObject
     */
    private function _setQueryFields($queryObject)
    {
        $this->queryObject = $queryObject;
        $this->queryFields = $queryObject->clause('select');
    }
}
