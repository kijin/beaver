
Introduction
============

Beaver is a very simple object-to-database mapper for **PHP 5.3** and above.
It allows you to do CRUD stuff through an object-oriented interface,
and also provides basic search and caching functions.

Beaver is designed to be used with [PDO](http://www.php.net/manual/en/book.pdo.php).
However, since Beaver uses dependency injection for database interactions,
any object that behaves like PDO (such as a custom wrapper for PDO) can be used in its place.
Beaver currently supports MySQL, PostgreSQL, and SQLite.
Other relational databases have not been tested.

Caching also uses dependency injection.
Any class which exposes the following methods is compatible with Beaver:

  * get($key, $value)
  * set($key, $value, $ttl)

[Memcached](http://www.php.net/manual/en/book.memcached.php) and [PHPRedis](http://github.com/nicolasff/phpredis) work with Beaver out of the box.
APC can be made compatible with Beaver by using the author's [OOAPC](http://github.com/kijin/ooapc) class.
The older [Memcache](http://www.php.net/manual/en/book.memcache.php) extension is incompatible because it requires an additional argument in `set()`.

Beaver released under the [GNU Lesser General Public License, version 3](http://www.gnu.org/copyleft/lesser.html).


User Guide
==========

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

Data Definition
---------------

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

`protected static $_table` is required, because Beaver doesn't do inflections automatically.
You can point your classes at any table or view you want.

`protected static $_pk` is optional. It defaults to `id`, so you only need to override it
if you want to use a column other than `id` as your primary key.

All other properties should mirror database columns. You may specify default values.

Beaver will treat all properties, both public and protected, as names of database columns.
This will cause errors if you define properties that don't exist in the database.
If you need to define additional properties, make them begin with an underscore (`_`).
Properties that begin with an underscore will be ignored by Beaver.

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

#### Built-In Searching

Now, this is where it gets fun.

If you want to fetch all users whose `group_id` is 42, this is what you do:

    $objects = User::get_if_group_id(42);

Just call `get_if_<PROPERTY>()` and Beaver will hand you an array of all objects that match your query.
This works with all properties, except those that begin with an underscore (`_`).
(Properties that begin with an underscore are not mapped to database columns, as noted above.)

You can also sort the results by the same column, another column, or a combination of columns:

    $objects = User::get_if_group_id(42, 'name+');
    $objects = User::get_if_group_id(42, 'karma_points-');
    $objects = User::get_if_group_id(42, 'name+', 20);
    $objects = User::get_if_group_id(42, 'karma_points-,name+', 20, 40);

The first example above returns all users in group #42, sorted by name in ascending order.
The second example returns all users in the same group, sorted by karma points in descending order.
The third example is the same as the first example, but only returns the first 20 results.
The fourth example sorts results by karma points in descending order followed by name in ascending order,
skips the first 40 results, and returns the next 20. This is the kind of thing that you want in pagination.

If the `+` or `-` sign is omitted, ascending order is assumed.

Beaver also supports basic comparison operators when searching.
For example, the following example returns all users whose age is 30 or greater.

    $objects = User::get_if_age_gte(30, 'name+');

The next example returns the first 50 users whose age is between 30 and 40.

    $objects = User::get_if_age_between(array(30, 40), 'id+', 50);

The next example returns the second page of the list of users whose name starts with "John" (20 per page, ordered by age).

    $objects = User::get_if_name_startswith('John', 'age+', 20, 20);

In total, Beaver supports 12 operators for searching.

  * `_gte` matches values that are greater than or equal to the argument.
  * `_lte` matches values that are less than or equal to the argument.
  * `_gt` matches values that are greater than, but not equal to, the argument.
  * `_lt` matches values that are less than, but not equal to, the argument.
  * `_between` matches values that are between two values, including the endpoints. _The argument must be an array._
  * `_xbetween` matches values that are between two values, excluding the endpoints. _The argument must be an array._
  * `_not` matches all values _except_ the argument.
  * `_in` matches any value that is listed in the argument. _The argument must be an array._
  * `_notin` matches any value that is not listed in the argument. _The argument must be an array._
  * `_startswith` matches all strings that start with the argument.
  * `_endswith` matches all strings that end with the argument.
  * `_contains` matches all strings that contains the argument.

Note that operators are evaluated only if the full method name does not match a property name.
So if you have two properties named `age` and `age_lt`, all calls to `get_if_age_lt()` will be interpreted
as simple matches on `age_lt`, rather than as less-than matches on `age`.
If you find yourself in this rare situation and you need to do less-than matches on `age`,
call `get_if_age__lt()` instead. (Notice the extra underscore that helps disambiguate your query.)

Searching may be slow if the columns you're using for searching and sorting are not indexed.
Also, `_endswith` and `_contains` are always slow, so avoid these if you care about performance.

Case sensitivity of string comparisons depends on the type of database and other settings.

#### Custom Searching

Beaver can't do joins, subqueries, or anything else that isn't covered in the examples above.
But even if Beaver can't write those queries for you, it can make it easier for you
to make various `SELECT` queries in a convenient and secure way.

    $objects = User::select('WHERE group_id IN (SELECT id FROM groups WHERE name = ?)', $param);

The second argument should be an array of parameters, corresponding to placeholders in the query string.
Passing parameters separately is a very good way to prevent SQL injection vulnerabilities.
If you don't have any parameters to pass, you can skip the second argument.

Queries such as the above can be encapsulated in a method of the `User` class.
Beaver does not care if you add your own methods to classes, as long as you don't interfere with Beaver's core functions.
You can, for example, implement your own `save()` method that performs some checks before calling `parent::save()`.

The above syntax only works if the query returns individual rows, including the primary key.
For queries that don't return individual rows (such as anything with a `GROUP BY` clause),
and for all non-`SELECT` queries, you'll have to talk to the database connection (PDO object) directly.

Caching
-------

If you want Beaver to cache the result of a query for a while,
just add another argument to `get()`, `get_array()`, `get_if_<PROPERTY>`, and `select()`.

    $obj = User::get($id, 300);  // Cache for 300 seconds = 5 minutes.

Note that you need to use arrays when calling `get_array()` with a caching argument,
because otherwise Beaver can't distinguish the caching argument from all the other arguments.

    $primary_keys = array($id1, $id2, $id3, $id4 /* ... */ );
    $objects = User::get_array($primary_keys, 300);

Likewise, when calling `get_if_<PROPERTY>()` or `select()` with a caching argument,
make sure you don't skip optional arguments, such as sorting, limit/offset, or parameters.
You can use `null` to skip the limit/offset arguments, and `array()` to indicate an empty parameter list.

    $objects = User::get_if_age_lte(30, 'name+', null, null, 300);  // OK
    $objects = User::get_if_age_lte(30, 300);                       // WRONG

    $objects = User::select('WHERE group_id IS NULL', array(), 300);  // OK
    $objects = User::select('WHERE group_id IS NULL', 300);           // WRONG
