<?php

namespace Mini\Entity;

class EntitySerializer
{
    private $transformCache = [];

    private static $currentInstance;

    public static function instance()
    {
        if (! self::$currentInstance) {
            self::$currentInstance = new self;
        }

        return self::$currentInstance;
    }

    public function serialize(Entity $entity = null)
    {
        if (! $entity) {
            return null;
        }

        $className = get_class($entity);

        if (! isset($this->transformCache[$className])) {
            $entity = $this->flattenEntity($entity);
            $format = $this->makeDefaultFormat($entity);
            $fn = $this->makeTransformFunction($format);
            $this->transformCache[$className] = $fn;
        } else {
            $fn = $this->transformCache[$className];
        }

        return $fn($entity->fields);
    }

    private function processFormatDefinition(Entity $instance, $rawKey, array $format)
    {
        $definition = isset($instance->definition[$rawKey]) ? $instance->definition[$rawKey] : null;

        if ($definition === null) {
            return $format;
        }

        if (strpos($definition, 'integer') !== false || strpos($definition, 'pk') !== false) {
            $format['integer'] = true;
        } elseif (strpos($definition, 'float') !== false ||
            strpos($definition, 'double') !== false ||
            strpos($definition, 'decimal') !== false) {
            $format['float'] = true;
        } elseif (strpos($definition, 'boolean') !== false) {
            $format['boolean'] = true;
        } elseif (strpos($definition, 'datetime') !== false || strpos($definition, 'timestamp') !== false) {
            $format['datetime'] = true;
        } elseif (strpos($definition, 'date') !== false) {
            $format['date'] = true;
        }

        return $format;
    }

    private function getParentFormatContext(Entity $entity, array &$root, $field)
    {
        $isRelation = false;
        $format = &$root['format'];
        $formatByKey = &$root['formatByKey'];
        $relations = $entity->relations;
        $context = null;

        foreach ($entity->relations as $relationName => $relationOptions) {
            $prefix = $relationName . '_';

            if (strpos($field, $prefix) !== 0) {
                continue;
            }

            $key = substr($field, strlen($prefix));

            if ($key != 'id' && isset($entity->definition[$field])) {
                continue;
            }

            if (! isset($formatByKey[$relationName])) {
                $currentFormat = [
                    'key' => $relationName,
                    'object' => true,
                    'prefix' => $relationName . '_',
                    'child' => []
                ];
                $formatByKey[$relationName] = &$currentFormat;
                $format[] = &$currentFormat;
            } else {
                $currentFormat = &$formatByKey[$relationName];
            }

            if (! isset($relations[$relationName]['instance'])) {
                $relations[$relationName]['instance'] = new $relations[$relationName]['class'];
            }

            $visible = $relations[$relationName]['instance']->visible;
            $isRelation = true;

            if (isset($visible[0]) && ! in_array($key, $visible)) {
                return null;
            }

            $context = [
                'entity' => $relations[$relationName]['instance'],
                'format' => &$currentFormat['child'],
                'field' => $key,
                'key' => $key
            ];

            break;
        }

        if (! $isRelation && isset($entity->visible[0]) && ! in_array($field, $entity->visible)) {
            return null;
        }

        if (! $isRelation) {
            $prefix = null;
            $required = null;

            if ($entity->prefixAsObject) {
                foreach ($entity->prefixAsObject as $availablePrefix) {
                    if (strpos($field, $availablePrefix) === 0) {
                        $prefix = $availablePrefix;
                        $required = true;
                    }
                }
            }

            if ($prefix) {
                if (! isset($formatByKey[$prefix])) {
                    $currentFormat = [
                        'key' => $prefix,
                        'object' => true,
                        'prefix' => $prefix . '_',
                        'child' => []
                    ];
                    if ($required) {
                        $currentFormat['required'] = true;
                    }
                    $formatByKey[$prefix] = &$currentFormat;
                    $format[] = &$currentFormat;
                } else {
                    $currentFormat = &$formatByKey[$prefix];
                }

                $context = [
                    'entity' => $entity,
                    'format' => &$currentFormat['child'],
                    'key' => substr($field, strlen($prefix) + 1),
                    'field' => $field
                ];
            } else {
                $context = [
                    'entity' => $entity,
                    'format' => &$root['format'],
                    'key' => $field,
                    'field' => $field
                ];
            }
        }

        return $context;
    }

    public function flattenEntity(Entity $entity)
    {
        $entity = clone $entity;
        foreach ($entity->relations as $relationKey => $value) {
            $relationInstance = $entity->getRelation($relationKey);
            if (! $relationInstance) {
                continue;
            }
            foreach ($relationInstance->fields as $relationField => $value) {
                $entity->fields[$relationKey . '_' . $relationField] = $value;
            }
        }
        return $entity;
    }

    private function makeDefaultFormat(Entity $entity)
    {
        $root = [
            'format' => [],
            'formatByKey' => []
        ];

        foreach ($entity->fields as $field => $value) {
            $context = $this->getParentFormatContext($entity, $root, $field);

            if (! $context) {
                continue;
            }

            $format = &$context['format'];
            $format[] = $this->processFormatDefinition(
                $context['entity'],
                $context['field'],
                [
                    'key' => $context['key']
                ]
            );
        }

        return $root['format'];
    }

    private function makeTransformFunctions($format)
    {
        $functions = [];

        foreach ($format as $field) {
            $key = $field['key'];
            $prefix = isset($field['prefix']) ? $field['prefix'] : '';
            $isObject = isset($field['object']) ? $field['object'] : false;

            if (! $isObject) {
                $transformFunction = function (&$object, $row) use ($key, $field, $prefix) {
                    $value = isset($row[$prefix . $key]) ? $row[$prefix . $key] : null;

                    if (isset($field['integer'])) {
                        $value = intval($value);
                    } elseif (isset($field['boolean'])) {
                        $value = ! is_null($value) ? !! $value : null;
                    } elseif (isset($field['float'])) {
                        $value = floatval($value);
                    } elseif (isset($field['date']) || isset($field['datetime'])) {
                        $value = $value ? $value : null;
                    }

                    if (env('CONVERT_CAMEL_CASE')) {
                        $key = camel_case($key);
                    }

                    $object[$key] = $value;
                };
            } elseif ($isObject && isset($field['child'])) {
                $innerFunctions = $this->makeTransformFunctions(array_map(
                    function (&$innerField) use ($prefix) {
                        if ($prefix) {
                            $innerField['prefix'] = $prefix;
                        }

                        return $innerField;
                    },
                    $field['child']
                ));


                $transformFunction = function (&$object, $row) use ($key, $field, $innerFunctions) {
                    $innerObject = [];

                    foreach ($innerFunctions as $fn) {
                        $fn($innerObject, $row);
                    }

                    if (count(array_filter(array_values($innerObject))) || isset($field['required'])) {
                        $object[$key] = $innerObject;
                    }
                };
            }

            $functions[] = $transformFunction;
        }

        return $functions;
    }

    private function makeTransformFunction($format)
    {
        $functions = $this->makeTransformFunctions($format);

        return function ($row) use ($functions) {
            $object = [];
            foreach ($functions as $fn) {
                $fn($object, $row);
            }
            return $object;
        };
    }
}
