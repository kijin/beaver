
Introduction
============

Beaver is a very simple object-to-database mapper for **PHP 5.3** and above.
It allows you to do CRUD stuff through an object-oriented interface,
and also provides basic search and caching functions.

Beaver is designed to be used with [PDO](http://www.php.net/manual/en/book.pdo.php).
However, since Beaver uses dependency injection for database interactions,
any class that behaves like PDO can be used in its place.
Currently, Beaver supports MySQL, PostgreSQL, and SQLite.
Other relational databases have not been tested.

Caching also uses dependency injection.
Any class which exposes the following methods is compatible with Beaver:

  * get($key, $value)
  * set($key, $value, $ttl)

[Memcache](http://www.php.net/manual/en/book.memcache.php),
[Memcached](http://www.php.net/manual/en/book.memcached.php),
and [PHPRedis](http://github.com/nicolasff/phpredis) are all compatible out of the box.
APC can be used with Beaver through the [OOAPC](http://github.com/kijin/ooapc) class.

Beaver released under the [GNU Lesser General Public License, version 3](http://www.gnu.org/copyleft/lesser.html).


User Guide
==========

Definition
----------

Beaver doesn't create tables for you, so you need to create them yourself.
This guide assumes that you have a database table as defined below:

    CREATE TABLE users
    (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(80) NOT NULL,
        email VARCHAR(80) NOT NULL,
        password VARCHAR(80) NOT NULL,
        age INTEGER NOT NULL,
        karma_points INTEGER NOT NULL DEFAULT 0
    );

Now you need to define the corresponding class.
It's your job to update this class if you change your database schema,
because Beaver is too lightweight to handle that for you.

    class User extends Beaver\Base
    {
        protected static $_table = 'users';  // Required
        protected static $_pk = 'id';        // Optional
        public $id;
        public $name;
        public $email;
        public $password;
        public $age;
        public $karma_points = 0;
    }

`$_table` is required, because Beaver doesn't do inflections automatically.
But this also means that you can point your classes at any table or view you want.

`$_pk` defaults to `id`, so you only need to override it
if you want to use a column other than `id` as your primary key.

Some notes about properties and database columns:

If you manually set the `id` property, or any other property marked as the primary key,
Beaver will try to use that value as the primary key of the new row.
On the other hand, if you don't set it (and therefore leave it `null`),
Beaver will try to insert a new row without a primary key.
This is recommended if your primary key automatically increments, as primary keys usually do.
In that case, the `id` property will only become accessible
after you have saved the object (by calling `save()`) for the first time.

Beaver will treat all properties, both public and protected, as names of database columns.
This will cause errors if you define properties that don't exist in the database.
If you need to define additional properties, make them begin with an underscore (`_`).
Properties that begin with an underscore will be ignored by Beaver.

Configuration
-------------

Beaver doesn't manage database connections for you.
So you connect to the database first, and then inject the PDO object into Beaver.

    $pdo = new PDO('mysql:host=localhost;dbname=test', 'username', 'password');
    Beaver\Base::set_database($pdo);

Now, do the same for the cache connection.
This is optional. Beaver also works perfectly fine without caching.

    $cache = new Memcached;
    $cache->addServer('127.0.0.1', 11211);
    Beaver\Base::set_cache($cache);

That's it. There's no other configuration to make.

Everyday Usage
--------------

### Creating New Objects (INSERT)

Well, how else did you think it would work?

    $obj = new User;
    $obj->name = 'John Doe';
    $obj->email = 'john_doe@example.com';
    $obj->password = hash('sha512', $password.$salt);
    $obj->age = 30;
    $obj->save();

### Modifying Objects (UPDATE)

There are two ways to modify an existing object. Here's the first way:

    $obj->name = 'John Williams';
    $obj->email = 'new_email@example.com';
    $obj->save();

And here's the second way, which may give you better performance in some cases:

    $obj->save(array('name' => 'John Williams', 'email' => 'new_email@example.com'));

The difference is that the first method makes Beaver save every property again,
because Beaver cannot detect which properties have changed and which have not.
The second method only saves properties that you specify in the array.

### Deleting Objects (DELETE)

If you want to delete an object:

    $obj->delete();

Note that deleted objects can be re-inserted by calling `save()` again.

### Finding Objects (SELECT)

Beaver provides several different ways to find objects that have been previously saved.

If you know the primary key of the object you're looking for:

    $obj = User::get($id);
    echo $obj->name;
    echo $obj->email;

Note that `get()` will return `null` if the primary key cannot be found.
If you try to grab properties without first checking that it's not `null`,
PHP will slap you with a "fatal error".

If you want to fetch multiple objects at once, all by primary key:

    $objects = User::get_array($id1, $id2, $id3, $id4 /* ... */ );
    foreach ($objects as $obj)
    {
        echo $obj->name;
        echo $obj->email;
    }

Objects will be returned in the same order as the primary keys used in the argument.
Objects that are not found will be returned as `null`,
which means that the returned array may contain `null` in some places.

If you have a lot of primary keys to look up, you can also pass an array:

    $primary_keys = array($id1, $id2, $id3, $id4 /* ... */ );
    $objects = User::get_array($primary_keys);

If you want to fetch all objects with a certain property:

    $objects = User::find_by_age(30);

This works with all properties. Just call `find_by_<PROPERTY>` and you're done.
You can also sort the results by another column:

    $objects = User::find_by_age(30, 'name+');
    $objects = User::find_by_age(30, 'karma_points-');

The first example above returns all users whose age is 30, sorted by name in ascending order.
The second example returns all users whose age is 30, sorted by karma points in descending order.

#### Custom Queries

Beaver can't do joins, subqueries, limit/offset clauses, or anything else that isn't covered above.
But even if Beaver can't write those queries for you, it can make it easier for you
to make various `SELECT` queries in a convenient and secure way.

    $params = array($email, hash('sha512', $password.$salt));
    $objects = User::select('WHERE email = ? AND password = ?', $params);

Passing parameters as a separate array is a very good way to prevent SQL injection vulnerabilities.
If you don't have any parameters to pass, you can skip the second argument.

Queries such as the above can be encapsulated in a method of the `User` class.
Beaver does not care if you add your own methods to classes, as long as you don't interfere with Beaver's core functions.
You can, for example, implement your own `save()` method that performs some checks before calling `parent::save()`.

The above syntax only works if the query returns individual rows, including the primary key.
For queries that don't return individual rows (such as anything with a `GROUP BY` clause),
and all other types of queries, you'll have to talk to the database connection (PDO object) directly.

Caching
-------

If you want Beaver to cache the result of a query for a while,
just add another argument to `get()`, `get_array()`, `find_by_<PROPERTY>`, and `select()`.

    $obj = User::get($id, 300);  // Cache for 300 seconds = 5 minutes.

Note that you need to use arrays when calling `get_array()` with a caching argument,
because otherwise Beaver can't distinguish the caching argument from all the other arguments.

    $primary_keys = array($id1, $id2, $id3, $id4 /* ... */ );
    $objects = User::get_array($primary_keys, 300);

Likewise, when calling `find_by_<PROPERTY>` or `select()` with a caching argument,
make sure you don't skip optional arguments, such as sorting or parameters.

    $objects = User::find_by_age(30, 'name+', 300);  // OK
    $objects = User::find_by_age(30, 300);           // WRONG

    $objects = User::select('WHERE column_name IS NULL', array(), 300);  // OK
    $objects = User::select('WHERE column_name IS NULL', 300);           // WRONG
