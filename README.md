
Introduction
============

Beaver is a very simple object-to-database mapper for **PHP 5.3** and above.
It allows you to do CRUD stuff through an object-oriented interface,
and also provides basic search and caching functions.

Beaver is designed to be used with [PDO](http://www.php.net/manual/en/book.pdo.php).
However, since Beaver uses dependency injection for database interactions,
any class that behaves like PDO can be used in its place.
Beaver currently supports MySQL, PostgreSQL, and SQLite.
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
        id           INTEGER      PRIMARY KEY AUTO_INCREMENT,
        name         VARCHAR(128) NOT NULL,
        email        VARCHAR(128) NOT NULL,
        password     VARCHAR(128) NOT NULL,
        age          INTEGER      NOT NULL,
        karma_points INTEGER      NOT NULL DEFAULT 0,
        group_id     INTEGER
    );

Now you need to define the corresponding class in PHP.
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
        public $group_id;
    }

`$_table` is required, because Beaver doesn't do inflections automatically.
You can point your classes at any table or view you want.

`$_pk` defaults to `id`, so you only need to override it if you want to use a column other than `id` as your primary key.

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
    $obj->password = hash($password.$salt);
    $obj->age = 30;
    $obj->save();

Usually you would skip the primary key (`id` property) when assigning values to a new object.
Primary key are usually set to `AUTO_INCREMENT` or equivalent, in which case they are assigned by the database server.
But this means that the primary key (`id` property) will remain `null` until you save the object for the first time.
So, be careful not to refer to an object's primary key before it is saved.

If your table has a primary key that does not auto-increment, you may have to set it manually.
In that case, it's OK to assign whatever you want to the `id` property manually.
Beaver will try to honor your choices, while any mistakes will be caught by the database server.

### Modifying Objects (UPDATE)

There are two ways to modify an existing object. Here's the first way:

    $obj->name = 'John Williams';
    $obj->email = 'new_email@example.com';
    $obj->save();

And here's the second way, which may give you better performance in some cases:

    $obj->save(array('name' => 'John Williams', 'email' => 'new_email@example.com'));

When using the first method, Beaver saves every property again, because it can't detect which properties have changed.
When using the second method, only those properties that you specify in the array are updated.

### Deleting Objects (DELETE)

Here's how you delete an object:

    $obj->delete();

Note that deleted objects can be re-inserted by calling `save()` again.

Beaver currently can't delete more than one object at the same time.
If you need to delete many objects at the same time, write your own SQL query --
or better yet, see if you can use foreign keys to achieve the same effect without writing any additional queries.

### Finding Objects (SELECT)

Beaver provides several different ways to retrieve objects that have been previously saved.

If you know the primary key of the object you're looking for, this is by far the simplest method:

    $obj = User::get($id);
    echo $obj->name;
    echo $obj->email;

Note that `get()` will return `null` if the primary key cannot be found.
If you try to grab properties when it's `null`, PHP will slap you with a "fatal error".
So you'll need to add some checks before you take your app anywhere near production.

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

Now, this is where it gets fun. If you want to fetch all objects with a certain property, this is what you do:

    $objects = User::find_by_age(30);

This works with all properties. Just call `find_by_<PROPERTY>` and you're done.
You can also sort the results by another column:

    $objects = User::find_by_age(30, 'name+');
    $objects = User::find_by_age(30, 'karma_points-');

The first example above returns all users whose age is 30, sorted by name in ascending order.
The second example returns all users whose age is 30, sorted by karma points in descending order.

#### Custom Queries

Beaver can't do joins, subqueries, or anything else that isn't covered in the examples above.
But even if Beaver can't write those queries for you, it can make it easier for you
to make various `SELECT` queries in a convenient and secure way.

    $params = array($email, hash($password.$salt));
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
make sure you don't skip optional arguments, such as sorting, limit/offset, or parameters.

    $objects = User::find_by_age(30, 'name+', 300);  // OK
    $objects = User::find_by_age(30, 300);           // WRONG

    $objects = User::select('WHERE group_id IS NULL', array(), 300);  // OK
    $objects = User::select('WHERE group_id IS NULL', 300);           // WRONG
