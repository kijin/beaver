<?php

/**
 * -----------------------------------------------------------------------------
 *  B E A V E R   :   Super lightweight object-to-database mapper for PHP 5.3 +
 * -----------------------------------------------------------------------------
 * 
 * @package    Beaver
 * @author     Kijin Sung <kijin.sung@gmail.com>
 * @copyright  (c) 2010-2011, Kijin Sung <kijin.sung@gmail.com>
 * @license    LGPL v3 <http://www.gnu.org/copyleft/lesser.html>
 * @link       http://github.com/kijin/beaver
 * @version    0.2
 * 
 * -----------------------------------------------------------------------------
 * 
 * Copyright (c) 2010-2011, Kijin Sung <kijin.sung@gmail.com>
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
    protected static $_cache = null;
    protected $_is_unsaved_object = true;
    
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
    
    // Call this method to inject a Memcached object (or equivalent) to the ORM.
    
    final public static function set_cache($cache)
    {
        self::$_cache = $cache;
    }
    
    // Flag this object as saved. (This flag is used internally by the ORM.)
    
    final public function _flag_as_saved()
    {
        $this->_is_unsaved_object = false;
        return $this;
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
                $fields[] = $field;
                $values[] = $value;
            }
        }
        
        // If inserting a new row.
        
        if ($this->_is_unsaved_object)
        {
            $query = 'INSERT INTO ' . static::$_table . ' (';
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
            
            $this->_is_unsaved_object = false;
        }
        
        // If updating an existing row.
        
        else
        {
            $query = 'UPDATE ' . static::$_table . ' SET ';
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
        }
    }
    
    // Delete method.
    
    public function delete()
    {
        $ps = self::$_db->prepare('DELETE FROM ' . static::$_table . ' WHERE ' . static::$_pk . ' = ?');
        $ps->execute(array($this->{static::$_pk}));
        $this->_is_unsaved_object = true;
    }
    
    // Fetch a single object, identified by its ID.
    
    public static function get($id, $cache = false)
    {
        $result = static::select('WHERE ' . static::$_pk . ' = ?', array($id), $cache);
        return count($result) ? $result[0] : null;
    }
    
    // Fetch an array of objects, identified by their IDs.
    
    public static function get_array($ids, $cache = false)
    {
        // Look up the cache.
        
        if ($cache && self::$_cache)
        {
            $cache_key = '_BEAVER::' . get_called_class() . ':' . sha1(serialize($ids = (array)$ids));
            $cache_result = self::$_cache->get($cache_key);
            if ($cache_result !== false && $cache_result !== null) return $cache_result;
        }
        
        // Find some objects, preserving the order in the input array.
        
        $query = 'SELECT * FROM ' . static::$_table . ' WHERE ' . static::$_pk . ' IN (';
        $query .= implode(', ', array_fill(0, count($id), '?')) . ')';
        $ps = self::$_db->prepare($query);
        $ps->execute($id);
        
        $result = array_combine($id, array_fill(0, count($id), null));
        $class = get_called_class();
        while ($object = $ps->fetchObject($class))
        {
            $result[$object->{static::$_pk}] = $object->_flag_as_saved();
        }
        
        // Store in cache.
        
        if ($cache && self::$_cache) self::$_cache->set($cache_key, $result, (int)$cache);
        return $result;
    }
    
    // Generic select method.
    
    public static function select($where, $params = array(), $cache = false)
    {
        // Look up the cache.
        
        if ($cache && self::$_cache)
        {
            $cache_key = '_BEAVER::' . get_called_class() . ':' . sha1($where . "\n" . serialize($params));
            $cache_result = self::$_cache->get($cache_key);
            if ($cache_result !== false && $cache_result !== null) return $cache_result;
        }
        
        // Find some objects.
        
        $ps = self::$_db->prepare('SELECT * FROM ' . static::$_table . ' ' . $where);
        $ps->execute((array)$params);
        
        $result = array();
        $class = get_called_class();
        while ($object = $ps->fetchObject($class))
        {
            $result[] = $object->_flag_as_saved();
        }
        
        // Store in cache.
        
        if ($cache && self::$_cache) self::$_cache->set($cache_key, $result, (int)$cache);
        return $result;
    }
    
    // Other search methods.
    
    public static function __callStatic($name, $args)
    {
        // Check method name.
        
        $name = strtolower($name);
        if (strncmp($name, 'find_by_', 8))
        {
            throw new BadMethodCallException('Static method not found: ' . $name);
        }
        
        $field = substr($name, 8);
        if (!$field || !property_exists(get_called_class(), $field) || $field[0] === '_')
        {
            throw new BadMethodCallException('Property not found: ' . $field);
        }
        
        // Check arguments.
        
        if (!count($args)) throw new BadMethodCallException('Missing arguments');
        $value = $args[0];
        if (isset($args[1]))
        {
            $order_field = $args[1];
            if (strlen($order_field) && in_array($order_field[strlen($order_field) - 1], array('+', '-')))
            {
                $order_sign = ($order_field[strlen($order_field) - 1] === '+') ? 'ASC' : 'DESC';
                $order_field = substr($order_field, 0, strlen($order_field) - 1);
            }
            if (!$order_field || !property_exists(get_called_class(), $order_field) || $order_field[0] === '_')
            {
                throw new BadMethodCallException('Property not found: ' . $order_field);
            }
        }
        
        // Find all matching objects.
        
        $query = 'WHERE ' . $field . ' = ?';
        if (isset($order_field))
        {
            $query .= ' ORDER BY ' . $order_field;
            if (isset($order_sign)) $query .= ' ' . $order_sign;
        }
        
        return static::select($query, array($value));
    }
}

// Exceptions.

class Exception extends \Exception { }
class BadMethodCallException extends \BadMethodCallException { }
