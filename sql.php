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

class Types {
    protected function __construct() { }
    protected function __clone() { }
    public function __wakeup() {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    private static $types = [];

    public static function add($nativeName, $sqlType, $serializer_cb, $unserializer_cb) {
        $serializer_cb = $serializer_cb ?? function ($v) { return $v; };
        $unserializer_cb = $unserializer_cb ?? function ($v) { return $v; };

        self::$types[$nativeName] = [
            "sqlType" => $sqlType,
            "serializer" => $serializer_cb,
            "unserializer" => $unserializer_cb
        ];
    }

    public static function remove($nativeName) {
        unset(self::$types, $nativeName);
    }

    public static function get($nativeName) {
        if (array_key_exists($nativeName, self::$types)) {
            return self::$types[$nativeName];
        }

        throw new \Error("Complex type " . $nativeName . " is not registered.");
    }

    public static function getSqlType($nativeName) {
        return self::get($nativeName)["sqlType"];
    }
}

\SQL\Types::add("string", "VARCHAR(768)", NULL, NULL);
\SQL\Types::add("int", "MEDIUMINT", NULL, NULL);
\SQL\Types::add("float", "FLOAT", NULL, NULL);
\SQL\Types::add("bool", "BOOL", NULL, NULL);
\SQL\Types::add(
    "DateTime", "TIMESTAMP",
    function($value) {
        return $value->format('Y-m-d H:i:s');
    },
    function($value) {
        return \DateTime::createFromFormat('Y-m-d H:i:s', $value);
    }
);

class Record {
    // These are the default table fields
    public int $id;
    public \DateTime $created_at;
    public \DateTime $updated_at;
    public \DateTime $deleted_at;

    function __construct() {}

    public static function tableName() {
        $reflect = new \ReflectionClass(static::class);
        return strtolower($reflect->getShortName());
    }

    public static function register() {
        $name = static::tableName();
        $sql = [trim(<<<SQL
            CREATE TABLE IF NOT EXISTS $name (
                id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP
            );
        SQL)];
        
        $cols = array_diff(
            static::getColumns(),
            ["id", "created_at", "updated_at", "deleted_at"]
        );

        foreach ($cols as $c) {
            if (static::isReference($c)) {
                // The type of the column, it being a foreign key,
                // will be the class name of the referenced model.
                // Thus, as can be seen in the link below, PHP
                // allows the calling of static methods when the
                // name of the class is known.
                // https://stackoverflow.com/a/3121559
                /**
                 * Foo::aStaticMethod();
                 * $classname = 'Foo';
                 * $classname::aStaticMethod(); // As of PHP 5.3.0
                 */
                $class_name = static::getTypeFor($c)->getName();
                $ref_table_name = $class_name::tableName();
                array_push($sql,
                    sprintf("ALTER TABLE %s ADD %s INT;", $name, $c),
                    sprintf(
                        "ALTER TABLE %s ADD CONSTRAINT fk_%s_%s FOREIGN KEY(%s) REFERENCES %s(id);",
                        $name, $name, $ref_table_name, $c, $ref_table_name
                    )
                );
            } else {
                $value = static::getDefaultValue($c);
                $default_value = (static::hasDefaultValue($c)) ? "DEFAULT($value)" : "" ;
                $not_null_constraint = (static::isNullable($c)) ? "" : "NOT NULL";
                $native_type = static::getTypeFor($c)->getName();
                $sql_type = \SQL\Types::getSqlType($native_type);
                array_push($sql, sprintf("ALTER TABLE %s ADD %s %s %s %s;",
                    $name, $c, $sql_type, $not_null_constraint, $default_value));

                $is_unique = str_contains(strtolower(static::getPropertyFor($c)->getDocComment()), "unique");
                if ($is_unique) {
                    array_push($sql, sprintf(
                        "ALTER TABLE %s ADD UNIQUE (%s);",
                        $name, $c
                    ));
                }
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
        $this->created_at = new \DateTime();

        // The name of the table...
        $name = $this::tableName();

        // and the initialized columns formatted as a string
        $cols = array_diff($this->getInitializedColumns(), ["id", "updated_at", "deleted_at"]);
        $columns = join(", ", $cols);

        // together with the values of these columns, also
        // written as arguments for the SQL statement.
        // example output: :val_col1, :val_col2, :val_col3
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
            if ($this::isReference($c)) {
                $stmt->bindValue(":val_".$c, $this->{$c}->id);
            } else {
                $type = \SQL\Types::get($this::getTypeFor($c)->getName());
                $serialized = call_user_func($type["serializer"], $this->{$c});
                $stmt->bindValue(":val_".$c, $serialized);
            }
        }

        $stmt->execute();
    }

    public function update() {
        if (!isset($this->id)) {
            throw new \Error("Must know ID to update row.");
        }

        $this->updated_at = new \DateTime;

        // Table name and ID of the row...
        $name = $this::tableName();
        $id = $this->id;

        // its values written as a string...
        // example output: col1 = :val_col1, col2 = :val_col2
        $values = join(", ", array_map(
            function ($c) { return sprintf("%s = :val_%s", $c, $c); },
            $this->getInitializedColumns()
        ));

        // all into a single SQL statement
        $stmt = \SQL\DB::getInstance()->prepare(<<<SQL
            UPDATE $name SET $values WHERE id = :id
        SQL);

        // filling the values in
        foreach ($this->getInitializedColumns() as $c) {
            if ($this::isReference($c)) {
                $stmt->bindValue(":val_$c", $this->{$c}->id);
            } else {
                $type = \SQL\Types::get($this::getTypeFor($c)->getName());
                $serialized = call_user_func($type["serializer"], $this->{$c});
                $stmt->bindValue(":val_$c", $serialized);
            }
        }
        $stmt->bindValue(":id", $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function delete($soft = true) {
        if (!isset($this->id)) {
            throw new \Error("Must know ID to update row.");
        }

        if ($soft) {
            $this->deleted_at = new \DateTime();
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
            if ($this::isReference($c)) {
                $stmt->bindValue(":val_$c", $this->{$c}->id);
            } else {
                $type = \SQL\Types::get($this::getTypeFor($c)->getName());
                $serialized = call_user_func($type["serializer"], $this->{$c});
                $stmt->bindValue(":val_$c", $serialized);
            }
        }

        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $valid = gettype($result) == "array" || gettype($result) == "object";

        if ($valid) foreach ($result as $key => $value) {
            if (isset($result[$key])) {
                if ($this::isReference($key)) {
                    $obj = new ($this::getTypeFor($key)->getName());
                    $obj->id = $value;
                    $obj->fetch();
                    $this->{$key} = $obj;
                } else {
                    $type = \SQL\Types::get($this::getTypeFor($key)->getName());
                    $unserialized = call_user_func($type["unserializer"], $value);
                    $this->{$key} = $unserialized;
                }
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
                if ($this::isReference($c)) {
                    if (!isset($this->{$c}->id)) {
                        $this->{$c}->fetch();
                    }
                    $stmt->bindValue(":val_$c", $this->{$c}->id);
                } else {
                    $type = \SQL\Types::get($this::getTypeFor($c)->getName());
                    $serialized = call_user_func($type["serializer"], $this->{$c});
                    $stmt->bindValue(":val_$c", $serialized);
                }
            }
        }
        $stmt->execute();

        $result = [];
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $obj = new static;
            foreach ($row as $key => $value) {
                if (isset($row[$key])) {
                    if ($this::isReference($key)) {
                        $class_name = $this::getTypeFor($key)->getName();
                        $ref_obj = new $class_name();
                        $ref_obj->id = $value;
                        $ref_obj->fetch();
                        $obj->{$key} = $ref_obj;
                    } else {
                        $type = \SQL\Types::get($this::getTypeFor($key)->getName());
                        $unserialized = call_user_func($type["unserializer"], $value);
                        $obj->{$key} = $unserialized;
                    }
                }
            }
            array_push($result, $obj);
        }

        return $result;
    }

    private static function getColumns() {
        $ref = new \ReflectionClass(static::class);
        $properties = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
        return array_map(function ($p) {
            return $p->name;
        }, $properties);
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

    private static function getTypeFor(string $columnName) {
        $ref = new \ReflectionClass(static::class);
        $properties = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($properties as $p) {
            if ($p->name == $columnName) {
                return $p->getType();
            }
        }
        throw new \Error("Column " . $columnName . " not present in table " . static::class);
    }

    private static function getPropertyFor(string $columnName) {
        $ref = new \ReflectionClass(static::class);
        $properties = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($properties as $p) {
            if ($p->name == $columnName) {
                return $p;
            }
        }
        throw new \Error("Column " . $columnName . " not present in table " . static::class);
    }

    private static function isReference(string $columnName) {
        return is_subclass_of(static::getTypeFor($columnName)->getName(), "\SQL\Record");
    }

    private static function isNullable(string $columnName) {
        return self::getTypeFor($columnName)->allowsNull();
    }

    private static function hasDefaultValue(string $columnName) {
        return static::getPropertyFor($columnName)->hasDefaultValue();
    }

    private static function getDefaultValue(string $columnName) {
        $prop = static::getPropertyFor($columnName);
        if ($prop->hasDefaultValue()) {
            return $prop->getDefaultValue();
        }
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