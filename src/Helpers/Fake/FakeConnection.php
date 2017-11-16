<?php

namespace Mini\Helpers\Fake;

use Mini\Entity\Behaviors\SqlBuilderAware;

class FakeConnection
{
    use SqlBuilderAware {
        insert as traitInsert;
        replace as traitReplace;
        insertOrUpdate as traitInsertOrUpdate;
        update as traitUpdate;
        delete as traitDelete;
    }

    private $context;

    public $database = 'test';

    public function __construct(array $context)
    {
        $this->context = $context;
    }

    public function prepare($sql)
    {
        return new FakeStatement(array_merge($this->context, ['sql' => $sql]));
    }

    public function exec($sql)
    {
        return (new FakeStatement(array_merge($this->context, ['sql' => $sql])))
            ->execute();
    }

    public function lastInsertId()
    {
        return 1;
    }

    public function insert($table, $fields)
    {
        $this->context['calls'][] = ['method' => 'insert', 'arguments' => func_get_args()];
        $this->traitInsert($table, $fields);
    }

    public function replace($table, $fields)
    {
        $this->context['calls'][] = ['method' => 'replace', 'arguments' => func_get_args()];
        $this->traitReplace($table, $fields);
    }

    public function insertOrUpdate($table, $fields, array $ignoredUpdates = [], array $extraUpdates = [])
    {
        $this->context['calls'][] = ['method' => 'insertOrUpdate', 'arguments' => func_get_args()];
        $this->traitInsertOrUpdate($table, $fields, $ignoredUpdates, $extraUpdates);
    }

    public function update($table, array $fields, array $filter)
    {
        $this->context['calls'][] = ['method' => 'update', 'arguments' => func_get_args()];
        $this->traitUpdate($table, $fields, $filter);
    }

    public function delete($table, array $filters)
    {
        $this->context['calls'][] = ['method' => 'delete', 'arguments' => func_get_args()];
        $this->traitDelete($table, $filters);
    }
}
