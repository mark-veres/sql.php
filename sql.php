<?php
/**
 * "sql.php" is a simple single-file PHP ORM.
 * Copyright (C) 2024  Mark Vereș <mark@markveres.ro>
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SQL;

class DB {
    private static $instance;
    private static $dsn, $user, $pass;
    protected function __construct() { }
    protected function __clone() { }
    public function __wakeup() {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    public static function getInstance() {
        if (!isset(self::$instance)) {
            self::$instance = new \PDO(self::$dsn, self::$user, self::$pass);
            self::$instance->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return self::$instance;
    }

    public static function setDSN($value) { self::$dsn = $value; }
    public static function setUsername($value) { self::$user = $value; }
    public static function setPassword($value) { self::$pass = $value; }
}

class Record {
    // These are the default table fields
    public int $id;
    public string $created_at;
    public string $updated_at;
    public string $deleted_at;

    function __construct() {

    }

    private static function tableName() {
        return strtolower(static::class);
    }

    public static function register() {
        $name = static::tableName();
        $sql = [trim(<<<SQL
            CREATE TABLE IF NOT EXISTS $name (
                id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP,
                deleted_at TIMESTAMP
            );
        SQL)];
        
        $cols = array_diff(
            static::getColumns(),
            ["id", "created_at", "updated_at", "deleted_at"]
        );

        foreach ($cols as $c) {
            if (static::isReference($c)) {
                // In this case, $type will be the name of the
                // class that represents the referenced table.
                $type = static::getTypeFor($c);
                array_push($sql,
                    sprintf("ALTER TABLE %s ADD %s INT;", $name, $c),
                    sprintf(
                        "ALTER TABLE %s ADD CONSTRAINT fk_%s_%s FOREIGN KEY(%s) REFERENCES %s(id);",
                        $name, $name, strtolower($type), $c, strtolower($type)
                    )
                );
            } else {
                $sql_type = static::getSqlTypeFor($c);
                array_push($sql, sprintf("ALTER TABLE %s ADD %s %s;", $name, $c, $sql_type));
            }
        }

        static::exec($sql);
    }

    public function clear() {
        foreach ($this->getColumns() as $column) {
            unset($this->{$column});
        }
    }

    public function create() {
        // The name of the table...
        $name = $this::tableName();

        // and the initialized columns formatted as a string
        $cols = array_diff($this->getInitializedColumns(), ["id"]);
        $columns = join(", ", $cols);

        // together with the values of these columns, also
        // written as arguments for the SQL statement
        $values = join(", ", array_map(
            function ($v) {
                return ":val_$v";
            },
            $cols)
        );

        // all in a single statement!
        $stmt = \SQL\DB::getInstance()->prepare(<<<SQL
            INSERT INTO $name ($columns) VALUES ($values)
        SQL);

        // filling the column values in
        foreach ($cols as $c) {
            $stmt->bindValue(":val_".$c, $this->{$c});
        }

        $stmt->execute();
    }

    public function update() {
        if (!isset($this->id)) {
            throw new \Error("Must know ID to update row.");
        }

        $name = $this::tableName();
        $id = $this->id;

        $values = join(", ", array_map(
            function ($c) { return sprintf("%s = :val_%s", $c, $c); },
            $this->getInitializedColumns()
        ));

        $stmt = \SQL\DB::getInstance()->prepare(<<<SQL
            UPDATE $name SET $values WHERE id = :id
        SQL);

        foreach ($this->getInitializedColumns() as $c) {
            $stmt->bindValue(":val_$c", $this->{$c});
        }
        $stmt->bindValue(":id", $id);
        $stmt->execute();
    }

    public function delete($soft = true) {
        if (!isset($this->id)) {
            throw new \Error("Must know ID to update row.");
        }

        if ($soft) {
            $this->deleted_at = date("Y-m-d H:i:s");
            $this->update();
        } else {
            $name = $this::tableName();
            $stmt = \SQL\DB::getInstance()->prepare(<<<SQL
                DELETE FROM $name WHERE id = :id;
            SQL);
            $stmt->bindValue(":id", $this->id, \PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    public function fetch() {
        $name = $this::tableName();
        $values = join(" AND ", array_map(
            function ($c) { return "$c = :val_$c"; },
            $this->getInitializedColumns()
        ));

        $stmt = \SQL\DB::getInstance()->prepare(<<<SQL
            SELECT * FROM $name WHERE $values AND deleted_at IS NULL
        SQL);

        foreach ($this->getInitializedColumns() as $c) {
            $stmt->bindValue(":val_$c", $this->{$c});
        }

        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        foreach ($result as $key => $value) {
            if (isset($result[$key])) {
                $this->{$key} = $value;
            }
        }
    }

    public function fetchAll() {
        $name = $this::tableName();
        $values = join(", ", array_map(
            function ($c) { return "$c = :val_$c"; },
            $this->getInitializedColumns()
        ));

        $stmt;
        if (sizeof($this->getInitializedColumns()) == 0) {
            $stmt = \SQL\DB::getInstance()->prepare(<<<SQL
                SELECT * FROM $name
            SQL);
        } else {
            $stmt = \SQL\DB::getInstance()->prepare(<<<SQL
                SELECT * FROM $name WHERE $values
            SQL);

            foreach ($this->getInitializedColumns() as $c) {
                $stmt->bindValue(":val_$c", $this->{$c});
            }
        }
        $stmt->execute();

        $result = [];
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $obj = new self;
            foreach ($row as $key => $value) {
                if (isset($row[$key])) {
                    $obj->{$key} = $value;
                }
            }
            array_push($result, $obj);
        }

        return $result;
    }

    private static function getColumns() {
        $ref = new \ReflectionClass(static::class);
        $properties = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
        return array_reverse(array_map(function ($p) {
            return $p->name;
        }, $properties));
    }

    private function getInitializedColumns() {
        return array_filter(
            $this::getColumns(),
            function ($c) {
                $ref = new \ReflectionProperty(static::class, $c);
                return $ref->isInitialized($this);
            }
        );
    }

    private function getColumnValues() {
        return array_map(function ($v) {
            return $this->{$v};
        }, $this->getInitializedColumns());
    }

    private static function getTypeFor(string $columnName) {
        $ref = new \ReflectionClass(static::class);
        $properties = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($properties as $p) {
            if ($p->name == $columnName) {
                return $p->getType()->getName();
            }
        }
        throw new \Error("Column " . $columnName . " not present in table " . static::class);
    }

    private static function getSqlTypeFor(string $columnName) {
        return match (static::getTypeFor($columnName)) {
            "string" => "MEDIUMTEXT",
            "int" => "MEDIUMINT",
            "float" => "FLOAT",
            default => "MEDIUMTEXT"
        };
    }

    private static function isReference(string $columnName) {
        return is_subclass_of(static::getTypeFor($columnName), "\SQL\Record");
    }

    private static function exec($commands) {
        foreach ($commands as $c) {
            try {
                \SQL\DB::getInstance()->exec($c);
            } catch (Exception|Error $e) {
                throw $e;
            }
        }
    }
}