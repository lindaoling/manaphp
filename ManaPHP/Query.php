<?php
namespace ManaPHP;

use ManaPHP\Exception\MisuseException;
use ManaPHP\Model\NotFoundException;

/**
 * Class Query
 * @package ManaPHP
 * @property-read \ManaPHP\Http\RequestInterface           $request
 * @property-read \ManaPHP\Paginator                       $paginator
 * @property-read \ManaPHP\Model\Relation\ManagerInterface $relationsManager
 */
abstract class Query extends Component implements QueryInterface, \IteratorAggregate
{
    /**
     * @var int
     */
    protected $_limit;

    /**
     * @var int
     */
    protected $_offset;

    /**
     * @var bool
     */
    protected $_distinct;

    /**
     * @var \ManaPHP\Model
     */
    protected $_model;

    /**
     * @var bool
     */
    protected $_multiple;

    /**
     * @var array
     */
    protected $_with = [];

    /**
     * @var bool
     */
    protected $_forceUseMaster = false;

    public function getIterator()
    {
        return new \ArrayIterator($this->all());
    }

    public function jsonSerialize()
    {
        return $this->all();
    }

    /**
     * @param string|\ManaPHP\Model $model
     *
     * @return static
     */
    public function setModel($model)
    {
        $this->_model = $model;

        return $this;
    }

    /**
     * @return \ManaPHP\Model|null
     */
    public function getModel()
    {
        return $this->_model;
    }

    /**
     * Sets SELECT DISTINCT / SELECT ALL flag
     *
     * @param bool $distinct
     *
     * @return static
     */
    public function distinct($distinct = true)
    {
        $this->_distinct = $distinct;

        return $this;
    }

    /**
     * @param array $filters
     *
     * @return static
     */
    public function whereSearch($filters)
    {
        $data = $this->request->get();

        foreach ($filters as $k => $v) {
            preg_match('#^(\w+)(.*)$#', is_int($k) ? $v : $k, $match);
            $field = $match[1];

            if (is_int($k)) {
                if (!isset($data[$field])) {
                    continue;
                }
                $value = $data[$field];
                if (is_string($value)) {
                    $value = trim($value);
                    if ($value === '') {
                        continue;
                    }
                }
                $this->where($v, $value);
            } else {
                $this->where($k, $v);
            }
        }

        return $this;
    }

    /**
     * @param string     $field
     * @param int|string $min
     * @param int|string $max
     *
     * @return static
     */
    public function whereDateBetween($field, $min, $max)
    {
        if (!$this->_model) {
            throw new MisuseException('use whereDateBetween must provide model');
        }

        if ($min && strpos($min, ':') === false) {
            $min = (int)(is_numeric($min) ? $min : strtotime($min . ' 00:00:00'));
        }
        if ($max && strpos($max, ':') === false) {
            $max = (int)(is_numeric($max) ? $max : strtotime($max . ' 23:59:59'));
        }

        if ($format = $this->_model->getDateFormat($field)) {
            if (is_int($min)) {
                $min = date($format, $min);
            }
            if (is_int($max)) {
                $max = date($format, $max);
            }
        } else {
            if ($min && !is_int($min)) {
                $min = (int)strtotime($min);
            }
            if ($max && !is_int($max)) {
                $max = (int)strtotime($max);
            }
        }

        return $this->whereBetween($field, $min ?: null, $max ?: null);
    }

    /**
     * Sets a LIMIT clause, optionally a offset clause
     *
     *<code>
     *    $builder->limit(100);
     *    $builder->limit(100, 20);
     *</code>
     *
     * @param int $limit
     * @param int $offset
     *
     * @return static
     */
    public function limit($limit, $offset = null)
    {
        $this->_limit = $limit > 0 ? (int)$limit : null;
        $this->_offset = $offset > 0 ? (int)$offset : null;

        return $this;
    }

    /**
     * @param string|array $with
     *
     * @return static
     */
    public function with($with)
    {
        if (is_string($with)) {
            if (strpos($with, ',') === false) {
                $with = [$with];
            } else {
                $with = (array)preg_split('#[\s,]+#', $with, -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        $this->_with = $this->_with ? array_merge($this->_with, $with) : $with;

        return $this;
    }

    /**
     * @param int $size
     * @param int $page
     *
     * @return static
     */
    public function page($size = null, $page = null)
    {
        if ($size === null) {
            $size = $this->request->get('size', 'int', 10);
        }

        if ($page === null) {
            $page = $this->request->get('page', 'int', 1);
        }

        $this->limit($size, $page ? ($page - 1) * $size : null);

        return $this;
    }

    /**
     * @param bool $multiple
     *
     * @return static
     */
    public function setFetchType($multiple)
    {
        $this->_multiple = $multiple;

        return $this;
    }

    /**
     * @param bool $asArray
     *
     * @return \ManaPHP\Model[]|\ManaPHP\Model|null|array
     */
    public function fetch($asArray = false)
    {
        if ($asArray) {
            $r = $this->execute();

            if ($this->_with) {
                $r = $this->relationsManager->earlyLoad($this->_model, $r, $this->_with);
            }

            return $r;
        }
        if ($this->_multiple === false) {
            $rs = $this->execute();
            if (isset($rs[0])) {
                $modelName = get_class($this->_model);
                $model = new $modelName($rs[0]);
                if ($this->_with) {
                    $this->relationsManager->lazyBindAll($model, $this->_with);
                }
                return $model;
            } else {
                return null;
            }
        } else {
            $modelName = get_class($this->_model);

            $models = [];
            foreach ($this->execute() as $k => $result) {
                $model = new $modelName($result);
                if ($this->_with) {
                    $this->relationsManager->lazyBindAll($model, $this->_with);
                }

                $models[$k] = $model;
            }

            return $models;
        }
    }

    /**
     * @param int $size
     * @param int $page
     *
     * @return \ManaPHP\Paginator
     */
    public function paginate($size = null, $page = null)
    {
        $this->page($size, $page);

        /** @noinspection SuspiciousAssignmentsInspection */
        $items = $this->all();

        if ($this->_limit === null) {
            $count = count($items);
        } elseif (count($items) % $this->_limit === 0) {
            $count = $this->count();
        } else {
            $count = $this->_offset + count($items);
        }

        $this->paginator->items = $items;

        return $this->paginator->paginate($count, $this->_limit, (int)($this->_offset / $this->_limit) + 1);
    }

    /**
     * @param bool $forceUseMaster
     *
     * @return static
     */
    public function forceUseMaster($forceUseMaster = true)
    {
        $this->_forceUseMaster = $forceUseMaster;

        return $this;
    }

    /**
     * @param string|array $fields
     *
     * @return array|null
     */
    public function first($fields = null)
    {
        $r = $this->select($fields)->limit(1)->fetch(true);
        return $r ? $r[0] : null;
    }

    /**
     * @param string|array $fields
     *
     * @return array
     */
    public function get($fields = null)
    {
        if (!$r = $this->first($fields)) {
            throw new NotFoundException('record is not exists');
        }

        return $r;
    }

    /**
     * @param string|array $fields
     *
     * @return array
     */
    public function all($fields = null)
    {
        return $this->select($fields)->fetch(true);
    }

    /**
     * @param string $field
     * @param mixed  $default
     *
     * @return mixed
     */
    public function value($field, $default = null)
    {
        $r = $this->first([$field]);
        return isset($r[$field]) ? $r[$field] : $default;
    }

    /**
     * @param string $field
     *
     * @return int|float|null
     */
    public function sum($field)
    {
        return $this->aggregate(['r' => "SUM($field)"])[0]['r'];
    }

    /**
     * @param string $field
     *
     * @return int|float|null
     */
    public function max($field)
    {
        return $this->aggregate(['r' => "MAX($field)"])[0]['r'];
    }

    /**
     * @param string $field
     *
     * @return int|float|null
     */
    public function min($field)
    {
        return $this->aggregate(['r' => "MIN($field)"])[0]['r'];
    }

    /**
     * @param string $field
     *
     * @return double|null
     */
    public function avg($field)
    {
        return (double)$this->aggregate(['r' => "AVG($field)"])[0]['r'];
    }

    /**
     * @param array $options
     *
     * @return static
     */
    public function options($options)
    {
        if (!$options) {
            return $this;
        }

        if (isset($options['limit'])) {
            $this->limit($options['limit'], isset($options['offset']) ? $options['offset'] : 0);
        } elseif (isset($options['size'])) {
            $this->page($options['size'], isset($options['page']) ? $options['page'] : null);
        }

        if (isset($options['distinct'])) {
            $this->distinct($options['distinct']);
        }

        if (isset($options['order'])) {
            $this->orderBy($options['order']);
        }

        if (isset($options['index'])) {
            $this->indexBy($options['index']);
        }

        if (isset($options['with'])) {
            $this->with($options['with']);
        }

        if (isset($options['group'])) {
            $this->groupBy($options['group']);
        }

        return $this;
    }
}