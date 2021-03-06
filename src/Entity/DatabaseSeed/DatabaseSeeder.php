<?php

namespace Mini\Entity\DatabaseSeed;

use Mini\Console\ConnectionWrapper;
use Mini\Entity\Mongo\Connection as MongoConnection;
use Mini\Entity\Connection as SqlConnection;
use MongoDB\Database;

class DatabaseSeeder
{
    /**
     * @var string
     */
    private $basePath;

    /**
     * @var string
     */
    private $type;

    /**
     * @var array
     */
    public $data;

    /**
     * @var ConnectionManager
     */
    public $connectionManager;

    public $idColumnsByTable = [];

    public function __construct($basePath, $type)
    {
        $this->basePath = $basePath;
        $this->type = $type;
    }

    public function loadData()
    {
        $files = glob($this->basePath . DIRECTORY_SEPARATOR . $this->type . DIRECTORY_SEPARATOR . '*.php');

        $results = [];

        foreach ($files as $file) {
            $pathPieces = explode(DIRECTORY_SEPARATOR, $file);
            $namePieces = explode('.', array_pop($pathPieces));
            $name = array_shift($namePieces);

            $results[$name] = require $file;
        }

        $this->data = $results;

        uasort($this->data, function ($a, $b) {
            $orderA = isset($a['order']) ? $a['order'] : PHP_INT_MAX;
            $orderB = isset($b['order']) ? $b['order'] : PHP_INT_MAX;

            return $orderA == $orderB ? 0 : ($orderA > $orderB ? 1 : -1);
        });
    }

    public function getTableNames()
    {
        return array_keys($this->data);
    }

    public function getConnection(array $spec, $verbose)
    {
        $connection = $this->connectionManager->getConnection($spec['connection']);
        if ($connection instanceof SqlConnection) {
            $connection = new ConnectionWrapper($connection);
            $connection->isVerbose = $verbose;
        }
        return $connection;
    }

    private function getIdColumns($spec, $tableName, $verbose)
    {
        if (count($this->idColumnsByTable) == 0) {
            $connection = $this->getConnection($spec, $tableName, $verbose);
            $connection->isVerbose = $verbose;
            $sql = 'SELECT k.column_name, t.table_name FROM information_schema.table_constraints t JOIN information_schema.key_column_usage k USING(constraint_name,table_schema,table_name) WHERE t.constraint_type=\'PRIMARY KEY\' AND t.table_schema=?';
            $stm = $connection->prepare($sql);
            $stm->execute([$connection->database]);
            $rows = $stm->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                if (! isset($this->idColumnsByTable[$row['table_name']])) {
                    $this->idColumnsByTable[$row['table_name']] = [];
                }
                $this->idColumnsByTable[$row['table_name']][] = $row['column_name'];
            }
        }

        return $this->idColumnsByTable[$tableName];
    }

    public function validate($verbose)
    {
        foreach ($this->data as $tableName => $spec) {
            $connection = $this->getConnection($spec, $verbose);

            if ($connection instanceof MongoConnection) {
                foreach ($spec['rows'] as $row) {
                    if (empty($row['_id'])) {
                        throw new \Exception('_id column is required on ' . $tableName);
                    }
                }
            } else {
                $idColumns = $this->getIdColumns($spec, $tableName, $verbose);

                if (count($idColumns) === 0) {
                    throw new \Exception('Primary keys not found on ' . $tableName);
                }

                foreach ($spec['rows'] as $row) {
                    foreach ($idColumns as $idColumn) {
                        if (empty($row[$idColumn])) {
                            throw new \Exception($idColumn . ' column is required on ' . $tableName);
                        }
                    }
                }
            }
        }
    }

    public function execute($verbose = false)
    {
        $this->loadData();
        $this->validate($verbose);

        foreach ($this->data as $tableName => $spec) {
            $connection = $this->getConnection($spec, $verbose);

            if ($connection instanceof MongoConnection) {
                $this->seedMongoDatabase($connection, $spec, $tableName, $verbose);
            } else {
                $this->seedSqlDatabase($connection, $spec, $tableName, $verbose);
            }
        }
    }

    public function seedSqlDatabase($connection, $spec, $tableName, $verbose)
    {
        $idColumns = $this->getIdColumns($spec, $tableName, $verbose);
        $stm = $connection->prepare('SET foreign_key_checks = 0;');
        $stm->execute();
        $ids = [];
        foreach ($spec['rows'] as $row) {
            $rowIds = [];
            foreach ($idColumns as $idColumn) {
                $rowIds[] = $row[$idColumn];
            }
            $ids[] = '(' . implode(', ', $rowIds) . ')';

            $connection->replace($tableName, $row);
        }
        if (count($ids)) {
            $sql = sprintf(
                'DELETE FROM ' . $tableName . ' WHERE (' . implode(', ', $idColumns) . ') NOT IN (%s)',
                implode(', ', $ids)
            );
            $stm = $connection->prepare($sql);
            $stm->execute();
        }
        $stm = $connection->prepare('SET foreign_key_checks = 1;');
        $stm->execute();
    }

    public function seedMongoDatabase($connection, $spec, $tableName, $verbose)
    {
        $database = new Database($connection->getDb(), $connection->getDbName());
        $collection = $database->$tableName;
        $ids = [];
        foreach ($spec['rows'] as $row) {
            $query = ['_id' => $row['_id']];
            $update = ['$set' => $row];
            $options = ['upsert' => true];
            $result = $collection->updateOne($query, $update, $options);
            if ($verbose) {
                echo "Executing: db.$tableName.update(".json_encode($query).", ".json_encode($update).", ".json_encode($options).");" . PHP_EOL;
            }
            $ids[] = $row['_id'];
        }
        $deleteQuery = ['_id' => ['$nin' => $ids]];
        if ($verbose) {
            echo "Executing: db.$tableName.delete(".json_encode($deleteQuery).");" . PHP_EOL;
        }
        $collection->deleteMany($deleteQuery);
    }
}
