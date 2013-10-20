<?php

/**
 * -----------------------------------------------------------------------------
 *  B E A V E R   :   Super lightweight object-to-database mapper for PHP 5.3 +
 * -----------------------------------------------------------------------------
 * 
 * @package    Beaver
 * @author     Kijin Sung <kijin@kijinsung.com>
 * @copyright  (c) 2010-2013, Kijin Sung <kijin@kijinsung.com>
 * @license    LGPL v3 <http://www.gnu.org/copyleft/lesser.html>
 * @link       http://github.com/kijin/beaver
 * @version    0.2.9
 * 
 * -----------------------------------------------------------------------------
 * 
 * Copyright (c) 2010-2013, Kijin Sung <kijin@kijinsung.com>
 * 
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser
 * General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * ----------------------------------------------------------------------------
 */

namespace Beaver;

// The base class. Extend this class for each object type you need.

class Base
{
    // The following properties should not be changed manually.
    
    protected static $_db = null;
    protected static $_db_is_pgsql = false;
    protected static $_db_prefix = '';
    protected static $_cache = null;
    protected static $_cache_prefix = '';
    protected $_is_unsaved = true;
    
    // The following properties may be overridden by children.
    
    protected static $_table = null;
    protected static $_pk = 'id';
    
    // Call this method to inject a PDO object (or equivalent) to the ORM.
    
    final public static function set_database($db)
    {
        self::$_db = $db;
        if ($db instanceof \PDO && $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql')
        {
            self::$_db_is_pgsql = true;
        }
    }
    
    // Call this method to set the database prefix (e.g. 'wp_').
    
    final public static function set_database_prefix($prefix)
    {
        self::$_db_prefix = $prefix;
    }
    
    // Call this method to inject a Memcached object (or equivalent) to the ORM.
    
    final public static function set_cache($cache)
    {
        self::$_cache = $cache;
    }
    
    // Call this method to set the cache prefix (e.g. 'wp_').
    
    final public static function set_cache_prefix($prefix)
    {
        self::$_cache_prefix = $prefix;
    }
    
    // Save any changes to this object.
    
    public function save($data = array())
    {
        // Make a list of fields and values to save.
        
        if (count($data))
        {
            $fields = array_keys($data);
            $values = array_values($data);
        }
        else
        {
            $fields = array();
            $values = array();
            foreach (get_object_vars($this) as $field => $value)
            {
                if ($field[0] === '_') continue;
                if ($field === static::$_pk && is_null($this->{static::$_pk}) && $this->_is_unsaved) continue;
                $fields[] = $field;
                $values[] = $value;
            }
        }
        
        // If inserting a new row.
        
        if ($this->_is_unsaved)
        {
            $query = 'INSERT INTO ' . self::$_db_prefix . static::$_table . ' (';
            $query .= implode(', ', $fields) . ') VALUES (';
            $query .= implode(', ', array_fill(0, count($values), '?'));
            $query .= ')';
            
            if (self::$_db_is_pgsql)
            {
                $query .= ' RETURNING ' . static::$_pk;
                $ps = self::$_db->prepare($query);
                $ps->execute($values);
                $this->{static::$_pk} = $ps->fetchColumn();
            }
            else
            {
                $ps = self::$_db->prepare($query);
                $ps->execute($values);
                $this->{static::$_pk} = self::$_db->lastInsertId();
            }
            
            $this->_is_unsaved = false;
        }
        
        // If updating an existing row.
        
        else
        {
            $query = 'UPDATE ' . self::$_db_prefix . static::$_table . ' SET ';
            $query .= implode(', ', array_map(function($str) { return $str . ' = ?'; }, $fields));
            $query .= ' WHERE ' . static::$_pk . ' = ?';
            $values[] = $this->{static::$_pk};
            
            $ps = self::$_db->prepare($query);
            $ps->execute($values);
            
            if (count($data))
            {
                foreach ($data as $field => $value)
                {
                    $this->$field = $value;
                }
            }
            
            if (self::$_cache) $this->invalidate_cache();
        }
    }
    
    // Delete method.
    
    public function delete()
    {
        $ps = self::$_db->prepare('DELETE FROM ' . self::$_db_prefix . static::$_table . ' WHERE ' . static::$_pk . ' = ?');
        $ps->execute(array($this->{static::$_pk}));
        $this->_is_unsaved = true;
        
        if (self::$_cache) $this->invalidate_cache();
    }
    
    // Invalidate the cache entry for this object.
    
    public function invalidate_cache()
    {
        $cache_key = self::$_cache_prefix . '_BEAVER::' . static::$_table . ':pk:' . $this->{static::$_pk};
        if (self::$_cache) self::$_cache->delete($cache_key);
    }
    
    // Invalidate the cache entry for multiple objects of the same class.
    
    public static function invalidate_cache_array($pklist)
    {
        $cache_key = self::$_cache_prefix . '_BEAVER::' . static::$_table . ':pk:';
        if (self::$_cache)
        {
            foreach ($pklist as $pk)
            {
                self::$_cache->delete($cache_key . $pk);
            }
        }
    }
    
    // Get the table name with the prefix.
    
    public static function get_table_name()
    {
        return self::$_db_prefix . static::$_table;
    }
    
    // Fetch a single object, identified by its ID.
    
    public static function get($id, $cache = false)
    {
        // Look up the cache.
        
        if ($cache && self::$_cache)
        {
            $cache_key = self::$_cache_prefix . '_BEAVER::' . static::$_table . ':pk:' . (string)$id;
            $cache_result = self::$_cache->get($cache_key);
            if ($cache_result !== false && $cache_result !== null) return unserialize($cache_result);
        }
        
        // Find the object.
        
        $ps = self::$_db->prepare('SELECT * FROM ' . self::$_db_prefix . static::$_table . ' WHERE ' . static::$_pk . ' = ?');
        $ps->execute(array($id));
        $class = get_called_class();
        $result = $ps->fetchObject($class);
        if (!$result) return null;
        $result->_is_unsaved = false;
        
        // Store in cache.
        
        if ($cache && self::$_cache) self::$_cache->set($cache_key, serialize($result), (int)$cache);
        return $result;
    }
    
    // Fetch an array of objects, identified by their IDs.
    
    public static function get_array($ids, $cache = false)
    {
        // This method can also be called with an arbitrary number of arguments instead of an array.
        
        if (!is_array($ids))
        {
            $ids = func_get_args();
            $cache = false;
        }
        
        // Remove any non-numeric keys from the array.
        
        $ids = array_values($ids);
        
        // Look up the cache.
        
        if ($cache && self::$_cache)
        {
            $cache_key = self::$_cache_prefix . '_BEAVER::' . static::$_table . ':arr:' . sha1(serialize($ids));
            $cache_result = self::$_cache->get($cache_key);
            if ($cache_result !== false && $cache_result !== null) return unserialize($cache_result);
        }
        
        // Find some objects, preserving the order in the input array.
        
        $query = 'SELECT * FROM ' . self::$_db_prefix . static::$_table . ' WHERE ' . static::$_pk . ' IN (';
        $query .= implode(', ', array_fill(0, count($ids), '?')) . ')';
        $ps = self::$_db->prepare($query);
        $ps->execute($ids);
        
        $result = array_combine($ids, array_fill(0, count($ids), null));
        $class = get_called_class();
        while ($object = $ps->fetchObject($class))
        {
            $object->_is_unsaved = false;
            $result[$object->{static::$_pk}] = $object;
        }
        
        // Store in cache.
        
        if ($cache && self::$_cache) self::$_cache->set($cache_key, serialize($result), (int)$cache);
        return $result;
    }
    
    // Fetch the first object that matches a condition. This is a shortcut to select().
    
    public static function get_first($where, $params = array(), $cache = false)
    {
        // Get the arguments and send them to select().
        
        $args = func_get_args();
        $result = call_user_func_array('static::select', $args);
        return current($result);
    }
    
    // Generic select method.
    
    public static function select($where, $params = array(), $cache = false)
    {
        // This method can also be called with an arbitrary number of arguments instead of an array.
        
        if (!is_array($params))
        {
            $params = func_get_args(); array_shift($params);
            $cache = false;
        }
        
        // Remove any non-numeric keys from the array.
        
        $params = array_values($params);
        
        // Look up the cache.
        
        if ($cache && self::$_cache)
        {
            $cache_key = self::$_cache_prefix . '_BEAVER::' . static::$_table . ':sel:' . sha1($where . "\n" . serialize($params));
            $cache_result = self::$_cache->get($cache_key);
            if ($cache_result !== false && $cache_result !== null) return unserialize($cache_result);
        }
        
        // Find some objects.
        
        $ps = self::$_db->prepare('SELECT * FROM ' . self::$_db_prefix . static::$_table . ' ' . $where);
        $ps->execute((array)$params);
        
        $result = array();
        $class = get_called_class();
        while ($object = $ps->fetchObject($class))
        {
            $object->_is_unsaved = false;
            $result[$object->{static::$_pk}] = $object;
        }
        
        // Store in cache.
        
        if ($cache && self::$_cache) self::$_cache->set($cache_key, serialize($result), (int)$cache);
        return $result;
    }
    
    // Other search methods.
    
    public static function __callStatic($name, $args)
    {
        // Check the method name.
        
        if (strlen($name) > 7 && !strncmp($name, 'get_if_', 7))
        {
            $search_field = substr($name, 7);
        }
        elseif (strlen($name) > 8 && !strncmp($name, 'find_by_', 8))  // Deprecated since 0.2.3
        {
            $search_field = substr($name, 8);
        }
        else
        {
            throw new BadMethodCallException('Static method not found: ' . $name);
        }
        
        // Check the search field name, including any operators.
        
        $comp_regex = '/^((?U).+)__?([gl]te?|x?between|not|in|notin|startswith|endswith|contains|)$/';
        if ($search_field[0] === '_')
        {
            throw new BadMethodCallException('Cannot search by non-existent property: ' . $search_field);
        }
        elseif (property_exists(get_called_class(), $search_field))
        {
            $search_comp = null;
        }
        elseif (preg_match($comp_regex, $search_field, $matches) && property_exists(get_called_class(), $matches[1]))
        {
            $search_field = $matches[1];
            $search_comp = $matches[2];
        }
        else
        {
            throw new BadMethodCallException('Cannot search by non-existent property: ' . $search_field);
        }
        
        // The first argument is the most important one.
        
        if (!count($args)) throw new BadMethodCallException('Search methods require at least one argument.');
        $search_value = (array)$args[0];
        
        // Look for additional arguments.
        
        if (isset($args[1]))  // Sort
        {
            $order_fields = explode(',', $args[1]);
            $order_fields_sql = array();
            foreach ($order_fields as $order_field)
            {
                $order_field = trim($order_field);
                if (!$order_field) continue;
                if (in_array($order_sign = $order_field[strlen($order_field) - 1], array('+', '-')))
                {
                    $order_sign = ($order_sign === '-') ? 'DESC' : 'ASC';
                    $order_field = substr($order_field, 0, strlen($order_field) - 1);
                }
                else
                {
                    $order_sign = 'ASC';
                }
                if (!strlen($order_field) || !property_exists(get_called_class(), $order_field) || $order_field[0] === '_')
                {
                    throw new InvalidArgumentException('Cannot order by non-existent property: ' . $order_field);
                }
                $order_fields_sql[] = $order_field . ' ' . $order_sign;
            }
        }
        if (isset($args[2]) && $args[2] !== null)  // Limit
        {
            $limit = (int)$args[2];
            $offset = 0;
        }
        if (isset($args[3]) && $args[3] !== null)  // Offset
        {
            $offset = (int)$args[3];
        }
        if (isset($args[4]))  // Cache
        {
            $cache = $args[4];
        }
        
        // Build the WHERE clause.
        
        switch ($search_comp)
        {
            case null: $query = 'WHERE ' . $search_field . ' = ?'; break;
            case 'gte': $query = 'WHERE ' . $search_field . ' >= ?'; break;
            case 'lte': $query = 'WHERE ' . $search_field . ' <= ?'; break;
            case 'gt': $query = 'WHERE ' . $search_field . ' > ?'; break;
            case 'lt': $query = 'WHERE ' . $search_field . ' < ?'; break;
            case 'between': $query = 'WHERE ' . $search_field . ' BETWEEN ? AND ?'; break;
            case 'xbetween': $query = 'WHERE ' . $search_field . ' > ? AND ' . $search_field . ' < ?'; break;
            case 'not': $query = 'WHERE ' . $search_field . ' != ?'; break;
            case 'in': $query = 'WHERE ' . $search_field . ' IN (' . implode(', ', array_fill(0, count($search_value), '?')) . ')'; break;
            case 'notin': $query = 'WHERE ' . $search_field . ' NOT IN (' . implode(', ', array_fill(0, count($search_value), '?')) . ')'; break;
            case 'startswith':
                $query = 'WHERE ' . $search_field . ' LIKE ? ESCAPE ?';
                $search_value[0] = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $search_value[0]) . '%';
                $search_value[1] = '\\';
                break;
            case 'endswith':
                $query = 'WHERE ' . $search_field . ' LIKE ? ESCAPE ?';
                $search_value[0] = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $search_value[0]);
                $search_value[1] = '\\';
                break;
            case 'contains':
                $query = 'WHERE ' . $search_field . ' LIKE ? ESCAPE ?';
                $search_value[0] = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $search_value[0]) . '%';
                $search_value[1] = '\\';
                break;
            default: $query = 'WHERE ' . $search_field . ' = ?';
        }
        
        if (isset($order_fields_sql) && count($order_fields_sql))
        {
            $query .= ' ORDER BY ' . implode(', ', $order_fields_sql);
        }
        
        if (isset($limit))
        {
            $query .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        
        // Return all matching objects.
        
        return static::select($query, $search_value, isset($cache) ? $cache : false);
    }
}

// Exceptions.

class Exception extends \Exception { }
class BadMethodCallException extends \BadMethodCallException { }
class InvalidArgumentException extends \InvalidArgumentException { }
