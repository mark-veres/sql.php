# sql.php
A simple Active Record-like ORM for PHP with features like:
- CRUD operations
- extensible type system
- support for ["complex types"](#complex-types)
- soft deletions

It shouldn't be used in real apps because it's realy flaky and not well done.

- [Installation](#installation)
- [The database](#the-database)
    + [Connecting to the database](#connecting-to-the-database)
    + [Using the database](#using-the-database)
- [Models](#models)
    + [Declaring models](#declaring-models)
    + [Declaring foreign keys](#declaring-foreign-keys)
    + [Table names](#table-names)
    + [Base model](#base-model)
- [Type system](#type-system)
    + [Native types](#native-types)
    + [Complex types](#complex-types)
- [Model operations](#model-operations)
    - [Create](#create)
    - [Fetch](#fetch)
    - [Update](#update)
    - [Delete](#delete)

## Installation
The instalation process consists of dragging the sql.php into your project folder and including it into your PHP file.

```php
require_once "sql.php";
```
## The database
### Connecting to the database
The database object is a [PDO class](https://www.php.net/manual/en/book.pdo.php) singleton that can be used everywhere in the file.

```php
\SQL\DB::setDSN("mysql:host=127.0.0.1:33060;dbname=db");
\SQL\DB::setUsername("admin");
\SQL\DB::setPassword("admin");
```

### Using the database
When the ORM features do not fully satisfy the need, you can always go classic by accessing the PDO class via the `\SQL\DB::getInstance()` function.

```php
\SQL\DB::getInstance()->exec("SELECT * FROM *");
```

## Models
Models are the in-code representation of a SQL table.

### Declaring models
Models are declared by extending the [base model](#base-model) and adding your own fields as typed public variables.
```php
class User extends \SQL\Record {
    public string $username;
    public string $password;
}
```

After declaring your models, you need to register them by calling the register function only once. It's inpractical to run this function every time your code runs.

```php
\User::register();
```

### Declaring foreign keys
In code, foreign keys will have the type of the referenced model, but in the database they are declared as an INT with a foreign key constraint.

```php
class Post extends \SQL\Record {
    public string $title;
    public string $content;
    public \User $author_id;
}
```

### Table names
Table names are the lowercase version name of the model class by default. They can be changed by overwriting the `Record::tableName()` function.

```php
class User {
    public static function tableName() {
        return "users";
    }
}
```

### Base model
These are the default table fields. **Do not overwrite them.**

```php
class Record {
    public int $id;
    public \DateTime $created_at;
    public \DateTime $updated_at;
    public \DateTime $deleted_at;
    ...
}
```

## Type system
Internally, the type system does not treat "complex" and native types differently. But its easier for us to differentiate between them.

Each type has a *serialization* and an *unserialization* function associated with it. These are mainly used by complex types in order to be easily stored and used by the database.

### Native types
From [PHPs variable types](https://www.php.net/manual/en/language.types.intro.php), we implement by default the following:
- `string` which corresponds to SQLs `MEDIUMTEXT`
- `int` => `INT`
- `float` => `FLOAT`
- `bool` => `BOOL`

The following types *(un)/serialization* functions do not transform the value of the type.

### Complex types
Complex types are classes that ease the process of working with database-stored information. They are associated with *(un)/serialization* functions make them storeable and useable for the database.

By default, the ORM implements the following complex types:
- [`DateTime`](https://www.php.net/manual/en/class.datetime.php), corresponding to SQLs `TIMESTAMP`

### Declaring new types
Firstly, declare your function, along with all the business-logic you expect it to deliever.

```php
class File {
    public $fileName;
    public $content;
    public function read();
    public function write();
    public static function getByName($name);
}
```

Then tell the ORM the name of the class and what SQL type it should be serialized into, together with the *serialization* and *unserialization* functions.

```php
\SQL\Types::add(
    "File", "VARCHAR(250)",
    function ($file) {
        return $file->fileName;
    },
    function ($value) {
        return \File::getByName($value);
    }
);
```

Remember that you shouldn't save the whole object in the database, only the information you need to reconstruct the object when it is retrieved.

#### DateTime
DateTime uses the format of `Y-m-d H:i:s`.  
Example usage:

```php
class Event extends \SQL\Record {
    public string $title;
    public \DateTime $date;
}

$e = new \Event;
$e->title = "B-day";
$e->date = new \DateTime("2024-04-10");
$e->create();
```

## Model operations

### Create
The create operation automatically fills the `created_at` field, leaving the `id` field to be generated by the database.

```php
$u = new \User;
$u->username = "mark";
$u->password = "test1234";
$u->create();
```

### Fetch
The `fetch` function fills out the current object with the corresponding data from the database.

```php
$u = new \User;
$u->username = "mark";
$u->fetch();
```

```php
$p = new \Post;
$p->author_id = $u->id;
$posts = $p->fetchAll();
```

### Update

```php
$u->password = "newpasswd";
$u->update();
```

### Delete
The ORM allows for "soft deletions" which change the value of the `deleted_at` field, signaling that the object should be left out of future queries.

"Hard deletions" will completely delete the row from the database.

**Deletions are soft by default.**

```php
$soft = true;
$u->delete($soft);
$u->delete(!$soft);
```

### Clear
This function clears the objects variables. It's usefull for reusing the same object for multiple queries.

```php
$u->clear();
```