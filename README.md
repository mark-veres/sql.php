# sql.php
PHP helpers for dealing with databases including:
- a [PDO](https://www.php.net/manual/en/book.pdo.php) singleton
- a simple "active record"-like ORM

This shouldn't be used in real apps because it's realy flaky and not well done.

## connecting to the database
```php
require_once "sql.php";

\SQL\DB::setDSN("mysql:host=127.0.0.1:33060;dbname=db");
\SQL\DB::setUsername("admin");
\SQL\DB::setPassword("admin");
\SQL\DB::getInstance()->exec("SELECT * FROM users");
```

## declaring models

```php
class User extends \SQL\Record {
    public string $username;
    public string $password;
}

// only call once
\User::register();
```

## foreign keys

```php
class Post extends \SQL\Record {
    public string $title;
    public string $content;
    public \User $author_id;
}
```

## CRUD operations
```php
// create
$u = new \User;
$u->username = "mark";
$u->password = "test1234";
$u->create();
$u->clear();

// read
$u->username = "mark";
$u->fetch();

// update
$u->password = "newpasswd";
$u->update();

// delete (only requires ID to be present)
$soft = true;
$u->delete($soft); // only changes deleted_at column
$u->delete(!$soft); // completely deletes the row from the DB

// fetch all
$p = new \Post;
$p->author_id = $u->id;
$posts = $p->fetchAll();
```

## table names
Table names are the name of the model class.  
I will add the ability to change table names.

## base model
```php
class Record {
    // These are the default table fields
    public int $id;
    public \DateTime $created_at;
    public \DateTime $updated_at;
    public \DateTime $deleted_at;
    ...
}
```