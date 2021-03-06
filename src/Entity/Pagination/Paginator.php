<?php

namespace Mini\Entity\Pagination;

use Mini\Entity\Entity;
use Mini\Entity\Query;

class Paginator
{
    const DEFAULT_PER_PAGE = 10;

    private $outputSerializer = null;

    public function __construct()
    {
        $this->outputSerializer = new OutputSerializer;
    }

    private function filterInputColumn ($value) {
        if (strpos($value, '.') !== false) {
            return implode(
                '.',
                array_map(
                    function ($innerValue) {
                        return '`' . preg_replace('/[^A-Za-z0-9_.]/', '', $innerValue) . '`';
                    },
                    explode('.', $value)
                )
            );
        } else {
            return '`' . preg_replace('/[^A-Za-z0-9_.]/', '', $value) . '`';
        }
    }

    private function generateDefaultFilterHandlers(array $options)
    {
        $baseQuery = $options['query'];
        $replaces = isset($options['replaces']) ? $options['replaces'] : [];

        $isReplacedMap = [];

        $result = [
            'search' => function ($query, $rawValue) use ($baseQuery, $replaces) {
                if (trim($rawValue) === '') {
                    return;
                }

                $subQuery = new Query;

                foreach ($baseQuery->spec['select'] as $index => $rawColumn) {
                    $column = strstr($rawColumn, ' as ')
                        ? explode(' as ', $rawColumn, 2)[0]
                        : explode(' AS ', $rawColumn, 2)[0];
                    $comparator = 'LIKE';
                    $value = '%' . $rawValue . '%';

                    if (isset($replaces[$column])) {
                        $replace = $replaces[$column];
                        $column = is_string($replace) ? $replace : $replace[0];
                        if (isset($isReplacedMap[$column])) {
                            continue;
                        }
                        $isReplacedMap[$column] = true;
                    }

                    $subQuery->where($column, 'LIKE', $value, 'OR');
                }

                $query->where($subQuery);
            }
        ];

        return $result;
    }

    public function processQueryHandlers(array $options)
    {
        $query = $options['query'];
        $filter = empty($options['filter']) ? [] : $options['filter'];
        $sort = empty($options['sort']) ? [] : $options['sort'];
        $filterHandlers = isset($options['filterHandlers']) ? $options['filterHandlers'] : [];
        $sortHandlers = isset($options['sortHandlers']) ? $options['sortHandlers'] : [];

        if (isset($options['replaces'])) {
            $replaces = [];

            foreach ($options['replaces'] as $key => $value) {
                if (strpos($key, '|') !== false) {
                    foreach (explode('|', $key) as $newKey) {
                        $replaces[$newKey] = $value;
                    }
                } else {
                    $replaces[$key] = $value;
                }
            }

            $options['replaces'] = $replaces;
        }

        $filterHandlers = array_merge(
            $this->generateDefaultFilterHandlers($options),
            $filterHandlers
        );

        foreach ($filter as $column => $value) {
            if (isset($filterHandlers[$column])) {
                $filterHandlers[$column]($query, $value);
            } else {
                if (isset($replaces[$column])) {
                    $replace = $replaces[$column];
                    $column = is_string($replace) ? $replace : $replace[0];
                    $comparator = is_string($replace) ? '=' : $replace[1];

                    $query->where($column, $comparator, $value);
                } else {
                    $query->where($this->filterInputColumn($column), '=', $value);
                }
            }
        }

        foreach ($sort as $column) {
           $direction = 'ASC';

            if (strpos($column, '-') === 0) {
                $direction = 'DESC';
                $column = substr($column, 1);
            }

            if (isset($sortHandlers[$column])) {
                $sortHandlers[$column]($query, $direction);
            } else {
                if (isset($replaces[$column])) {
                    $replace = $replaces[$column];
                    $column = is_string($replace) ? $replace : $replace[0];
                    $query->orderBy($column, $direction);
                } else {
                    $query->orderBy($this->filterInputColumn($column), $direction);
                }
            }
        }
    }

    private function processFormatDefinition(Entity $instance, $field)
    {
        $definition = isset($instance->definition[$field]) ? $instance->definition[$field] : null;

        if ($definition === null) {
            return $field;
        }

        if (strpos($definition, 'integer') !== false || strpos($definition, 'pk') !== false) {
            $field .= '|integer';
        } elseif (strpos($definition, 'float') !== false ||
            strpos($definition, 'double') !== false ||
            strpos($definition, 'decimal') !== false) {
            $field .= '|float';
        } elseif (strpos($definition, 'boolean') !== false) {
            $field .= '|boolean';
        } elseif (strpos($definition, 'datetime') !== false || strpos($definition, 'timestamp') !== false) {
            $field .= '|datetime';
        } elseif (strpos($definition, 'date') !== false) {
            $field .= '|date';
        }

        return $field;
    }

    private function generateDefaultFormat(array $options)
    {
        $query = $options['query'];
        $instance = $query->getInstance();
        $relations = $instance->relations;

        $format = [];

        foreach ($query->spec['select'] as $rawField) {
            $hasPoint = strpos($rawField, '.') !== false;
            $hasSpace = strpos($rawField, ' ') !== false;

            if ($hasPoint && $hasSpace) {
                $field = preg_split('/[. ]/', $rawField);
                $field = end($field);
            } else {
                $field = explode($hasPoint ? '.' : ' ', $rawField);
                $field = end($field);
            }

            $isRelation = false;
            $isPrefix = false;
            $required = null;

            $prefixAsObject = $instance->prefixAsObject ? $instance->prefixAsObject : [];

            foreach ($prefixAsObject as $prefixName) {
                $prefix = $prefixName . '_';
                if (strpos($field, $prefix) !== 0) {
                    continue;
                }
                $fieldWithoutPrefix = substr($field, strlen($prefix));
                $formatKey = $prefixName . '|object|prefix:' . $prefixName . '_|required';
                $isPrefix = true;
                if (! isset($format[$formatKey])) {
                    $format[$formatKey] = [];
                }
                $format[$formatKey][] = $this->processFormatDefinition(
                    $instance,
                    $fieldWithoutPrefix
                );
            }

            if (! $isPrefix)
            foreach ($relations as $relationName => $relationOptions) {
                $prefix = $relationName . '_';

                if (strpos($field, $prefix) !== 0) {
                    continue;
                }

                $fieldWithoutPrefix = substr($field, strlen($prefix));
                if ($fieldWithoutPrefix != 'id' && isset($instance->definition[$field])) {
                    continue;
                }
                $formatKey = $relationName . '|object|prefix:' . $relationName . '_';
                $isRelation = true;

                if (! isset($format[$formatKey])) {
                    $format[$formatKey] = [];
                }

                if (! isset($relations[$relationName]['instance'])) {
                    $relations[$relationName]['instance'] = new $relations[$relationName]['class'];
                }

                $relationInstance = $relations[$relationName]['instance'];
                $visible = $relationInstance->visible;

                if (isset($visible[0]) && ! in_array($fieldWithoutPrefix, $visible)) {
                    continue;
                }

                $format[$formatKey][] = $this->processFormatDefinition(
                    $relationInstance,
                    $fieldWithoutPrefix
                );
            }

            if (! $isRelation && ! $isPrefix) {
                $format[] = $this->processFormatDefinition(
                    $instance,
                    $field
                );
            }
        }

        return $format;
    }

    public function processQueryOptions(array $options)
    {
        $this->processQueryHandlers($options);
        $query = $options['query'];
        $page = isset($options['page']) ? $options['page'] : 1;
        $perPage = isset($options['perPage']) ? $options['perPage'] : self::DEFAULT_PER_PAGE;
        $format = isset($options['format']) ? $options['format'] : $this->generateDefaultFormat($options);
        $postProcess = isset($options['postProcess']) ? $options['postProcess'] : null;

        return [
            'sql' => $query->makeSql(),
            'from' => $query->makeFromSql(),
            'columnsQuantity' => count($query->spec['select']),
            'connectionInstance' => $query->connectionInstance,
            'bindings' => $query->spec['bindings'],
            'page' => $page,
            'perPage' => $perPage,
            'format' => $format,
            'postProcess' => $postProcess
        ];
    }

    public function paginateQuery(array $options)
    {
        return $this->paginateSql(
            $this->processQueryOptions($options)
        );
    }

    public function paginateSql(array $options)
    {
        $result = $this->runPaginatorSelect($options);
        return $this->makeOutput($result, $options);
    }

    public function makeOutput(array $result, array $options)
    {
        return $this->outputSerializer->serialize($result, $options);
    }

    private function runPaginatorSelect(array $options)
    {
        $bindings = $options['bindings'];
        $sql = $this->createPaginatorSelect($options);
        $stm = $options['connectionInstance']->prepare($sql);
        $stm->execute($bindings);
        $rows = $stm->fetchAll(\PDO::FETCH_ASSOC);
        $lastRow = array_pop($rows);

        return [
            'rows' => $rows,
            'total' => intval($lastRow['__pagination_total'])
        ];
    }

    public function createPaginatorSelect(array $options)
    {
        $initialSql = $options['sql'];
        $from = isset($options['from']) ? $options['from'] : null;
        $initialSql = preg_replace('@\n@', ' ', trim($initialSql));
        $columnsQuantity = $options['columnsQuantity'];
        $page = $options['page'];
        $perPage = $options['perPage'];

        $union = str_repeat('0,', $columnsQuantity) . 'found_rows()';
        $skip = ($page - 1) * $perPage;

        $sql = preg_replace(
            $from
                ? '/^SELECT (.*?)FROM (' . preg_quote($from) . ')(.*)$/i'
                : '/^SELECT (.*?)FROM(.*)$/i',
            ($from
                ? 'SELECT SQL_CALC_FOUND_ROWS t0.* FROM (SELECT $1, 0 as __pagination_total FROM $2$3) t0 '
                : 'SELECT SQL_CALC_FOUND_ROWS t0.* FROM (SELECT $1, 0 as __pagination_total FROM $2) t0 ') .
            ' LIMIT ' . $skip . ', ' . $perPage .
            ' UNION ALL SELECT ' . $union,
            $initialSql
        );

        return $sql;
    }
}
