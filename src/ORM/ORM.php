<?php
// Indicium Database Library
// Copyright(C) 2006-2016 McDaniel Consulting, LLC.  All rights resvered.
// @author Craig McDaniel <craig@craigify.com>
//
// Object Relation Mapper
// This is a mix between ActiveRecord and DataMapper patterns.  I think....
//
//
// Loading and syncing with database:
// ----------------------------------------------------------------------------------------------------------------------------------------
// load()                     Load by primary key into current object.
// loadByField()              Load by other unique key.  loadByIdentifier("one") expects 1 row to have unique identifier.
// save()                     Sync with database.
// find()                     Return single object if resultset count is 1, array of ORMobjects if count is > 1
// findFirst()                Execute a find call with a LIMIT 1 clause and return the first returned row
// findByField(value)         Find by field shortcut.  findByName("Craig")
// findAll()                  Returns array of ORMObjects even if resultset count is 1.
// findAllByField(value)      arr = ORM->findAllByType("employee")
// findByPrimaryKey(value)    Find by primary key value. 
//
// Working with data:
// ----------------------------------------------------------------------------------------------------------------------------------------
// getFields()                Return array of key=>value pairs of object's data.
// getDbFields()              Return array of key=>value pairs with database column names instead of object properties.
// setFields()                Use array of key=>value pairs to set object's data.
// updateFields()             Update fields with key=>value pairs.  Only update fields present in your array.
// ORM->fieldname             Access any field name with normal object notation.  e.g. ORM->name = "Craig"
// setField(value)            ORM->setName("Craig") sets ORM->name = "Craig"
// getField()                 ORM->getName returns value of ORM->name, which would be "Craig"
// isDirty()                  Return TRUE if object data is "dirty" or not synched with the db.  save() will solve this.
//
// Other methods:
// ----------------------------------------------------------------------------------------------------------------------------------------
// loadRelations()            Load any relations (has one, has many) manually.
// countTableRows()           Return total number of rows in the table
// countTotalRows()           Return the total amount of rows in your resultset if you were to NOT INCLUDE A LIMIT CLAUSE. Useful for pagination.
//
// Defining an ORM object
//
// 1. class MyObj extends ORM.  MyObj is the name of the database table. If they don't match, use setTableDbName()
// 2. myObj constructor must call the ORM constructor.
// 3. Use addMap() to map db column names to object field names.
// 4. Use addRelation() to define relation to other ORM objects you defined.
// 5. Use addUnique() to define any unique keys.
// 6. Use addHandler() to specify a reference to active database object (usually $maindb global)
//
//
// You can define callback methods in your ORM object that if defined will be called before and after certain operations://
//   onBeforeSet(&field, &value)  and  onAfterSet(&field, &value)
//   onBeforeSave()               and  onAfterSave()
//   onBeforeUpdate()             and  onAfterUpdate()
//
// This is executed for both load() and find() calls:
//   onBeforeLoad($type)          and  onAfterLoad($type)
//
// This is executed only on find() calls:
//   onAfterFind($type, $conditions, $order, $limit, &$resultset)
//

namespace Indicium\ORM;

// ORM relation types
define("ORM_HAS_ONE",        1001);    // 1 to 1 mapping with INNER JOIN
define("ORM_MIGHT_HAVE_ONE", 1002);    // 1 to 1 mapping with LEFT JOIN
define("ORM_BELONGS_TO",     1003);    // 1 to 1 mapping with INNER JOIN.
define("ORM_HAS_MANY",       1007);    // 1 to many mapping
define("ORM_HAS_AND_BELONGS_TO_MANY", 1009);
define("ORM_HABTM",          1009);

// Other constants
define("ORM_NEW_OBJECT",     2000);
define("ORM_THIS_OBJECT",    2002);
define("ORM_ARRAY",          2004);


abstract class ORM
{
   // All ORM objects store their data in this internal array.  This is done to stop too much object pollution with ORM stuff.
   protected $orm = array();


   // Constructor.
   public function __construct($loadRelations = TRUE)
   {
      $this->orm['data'] = array();
      $this->orm['loadRelations'] = $loadRelations;
      $this->orm['namespace'] = "\\"; // global namespace by default
      $this->orm['tableName'] = $this->getShortClassName(); // get_class($this);
      $this->orm['tableNameDb'] = $this->getShortClassName(); // get_class($this);
      $this->orm['primaryKey'] = NULL;
      $this->orm['unique'] = FALSE;
      $this->orm['fieldMap'] = array();
      $this->orm['objectFieldMap'] = array();
      $this->orm['conditions'] = array(); // conditions used every time.
      $this->orm['lastConditions'] = array(); // last conditions array for previous query.
      $this->orm['relations'] = array();
      $this->orm['queryQueue'] = array();
      $this->orm['dbSync'] = FALSE;
      $this->orm['isDirty'] = FALSE;      
      $this->orm['cascadeSave'] = FALSE;
      $this->orm['cascadeDelete'] = FALSE;
      $this->orm['reader'] = NULL;
      $this->orm['writer'] = NULL;
   }
   
   
   // Specify which namespace your classes are in.  Do this in the constructor of your concrete class
   // that inherits ORM.  This way when you call setRelation() and specify a related ORM class, we know
   // where to look.  Alternatively, you can speficy the full class name with namespace.
   // @param string $ns The namespace to find related ORM classes   
   public function setNamespace($ns)
   {
      $this->orm['namespace'] = $ns;
   }
   
   
   // Simultaneously set a reference to a QueryBuilder object internally for read and write operations.
   // The ORM layer needs a QueryBuilder object to perform various read and write DB operations, and
   // the following few methods provide functionality to set and retrieve references to those objects.
   // @param object $queryBuilder An instantiated and connected QueryBuilder object
   public function setQueryBuilder($queryBuilder)
   {
      $this->setQueryBuilderReader($queryBuilder);
      $this->setQueryBuilderWriter($queryBuilder);      
   }


   // Tell us what QueryBuilder object to use for read operations.
   // @param object $queryBuilder An instantiated and connected QueryBuilder object
   public function setQueryBuilderReader($queryBuilder)
   {
      $this->orm['reader'] = $queryBuilder;
   }


   // Tell us what QueryBuilder object to use for write operations.
   // @param object $queryBuilder An instantiated and connected QueryBuilder object
   public function setQueryBuilderWriter($queryBuilder)
   {
      $this->orm['writer'] = $queryBuilder;
   }
   
   
   // Retrieve a reference to QueryBuilder object for read operations.  This is used internally, but you
   // can use it too for whatever reason you might need.
   // @param object An instantiated and connected QueryBuilder object
   public function getReader()
   {
      return $this->orm['reader'];
   }


   // Retrieve a reference to QueryBuilder object for write operations.  This is used internally, but you
   // can use it too for whatever reason you might need.
   // @param object An instantiated and connected QueryBuilder object
   public function getWriter()
   {
      return $this->orm['writer'];
   }


   //////////////////////////////////////////////////////////////////////////////////////////
   // get, set and load methods.  Get data in and out of the current object.               //
   //////////////////////////////////////////////////////////////////////////////////////////


   // Intercept all property sets and direct them to the set() method.  We're able to determine the data
   // state of the ORM object this way, either dirty or not.
   public function __set($objField, $value)
   {
      $this->set($objField, $value);
   }
   
   
   // Intercept all property unsets.
   public function __unset($objField)
   {
      if (isset($this->orm['data'][$objField]))
      {
         unset($this->orm['data'][$objField]);
      }      
   }
   
   
   // Intercept isset calls.
   public function __isset($objField)
   {
      if (isset($this->orm['data'][$objField]))
      {
         return true;
      }
      else
      {
         return false;
      }
   }
   

   // Intercept all property gets and send them to get()
   public function __get($objField)
   {
      return $this->get($objField);
   }   
   
   
   // Set a value on the object using an internal data associative array. We do this so that we
   // can set the isDirty flag when the ORM object has been modified.
   //
   // Note: Why not just set them directly on the object?  Then subsequent sets will not trigger
   // this magic method __set(), since it only works when the property is not available.  Storing
   // them in an internal data array ensures that they never get set directly, so our magic methods
   // will always work.
   //
   public function set($objField, $objValue)
   {      
      foreach ($this->orm['objectFieldMap'] as $key => $value)
      {
         if (strcasecmp($objField, $key) == 0)
         {
            if (method_exists($this, "onBeforeSet"))
            {
               $this->onBeforeSet($objField, $objValue);
            }

            /* We did a case-insensitive match, but actualy set the variable in the object with the correct case! */
            $this->orm['data'][$key] = $objValue;
            $this->orm['isDirty'] = true;
	    
            if (method_exists($this, "onAfterSet"))
            {
               $this->onAfterSet($objField, $objValue);
            }
	    
         return $objValue;
         }	 
      }

      // If we get here, we're setting a value that isn't in our object map.  The isDirty flag won't get set.
      $this->orm['data'][$objField] = $objValue;

   return $objValue;
   }



  /* Set object fields by passing an array of key => value pairs.  Keys will be compared against the field map
   * in a CASE INSENSITIVE manner, just like set().
   */

   function setFields($fields)
   {
      $fields = array_change_key_case($fields, CASE_LOWER);
      $map = array_change_key_case($this->orm['objectFieldMap'], CASE_LOWER);

      foreach ($fields as $key => $value)
      {
         if (array_key_exists($key, $map))
         {
            $this->set($key, $value);
         }
      }
   }



  /* Return the value of an object variable.
   *
   * Return NULL if not defined.
   */

   public function get($objField)
   {      
      if (isset($this->orm['data'][$objField]))
      {
	 return $this->orm['data'][$objField];
      }
      else
      {
	 return null;
      }
   }



  /* Get the current fields and their values from the current object, returning them as a key => value array.
   * Any undefined field names get assigned NULL in the array automatically.
   */

   public function getFields()
   {
      foreach ($this->orm['objectFieldMap'] as $key => $value)
      {
         if (isset($this->orm['data'][$key]))
         {
            $fields[$key] = $this->orm['data'][$key];
         }
         else
         {
            $fields[$key] = NULL;
         }
      }

   return $fields;
   }


  /* Like getFields() except field names are the database column names instead of the object properties.
   */
   public function getDbFields()
   {
      foreach ($this->orm['fieldMap'] as $dbField => $objField)
      {         
         if (isset($this->$objField))
         {
            $fields[$dbField] = $this->$objField;
         }
         else
         {
            $fields[$dbField] = NULL;
         }
      }
      
      return $fields;
   }


  /* Return ALL fields and values from the object, except for private properties.
   */

   public function getAllFields()
   {
      $result = array_merge($this->getFields(), get_object_vars($this));
      unset($result['orm']);

   return $result;
   }



  /* Load data from the database and populate result into current instance of object.  Since an object
   * represents a single tuple, multiple rows (tuples) in the resultset of the query would result
   * in an error.
   *
   * Return TRUE on successful load.
   * Return FALSE if no record found, or if error.
   *
   */

   public function load($primaryKeyValue)
   {
      if (empty($this->orm['primaryKey']))
      {
         //error(E_WARNING, "No primary key defined, therefore I cannot load a tuple.");
         return FALSE;
      }

      $conditions = $this->ormConditions(array("{$this->orm['primaryKey']} = {$primaryKeyValue}"));
      $ret = $this->ormLoad(ORM_THIS_OBJECT, 1, $conditions);

   return $ret;
   }



  /* Load a tuple from the database, using a UNIQUE value for reference other than the primary key.
   *
   * loadBy("identifier", "uniquevalue") will work just like load()
   * Also magic method loadByN("value") applies.
   *
   * If the database operation returns multiple rows (tuples), we would also return an error here.
   *
   * Return TRUE on successful load, or FALSE if no record found, or if error.
   *
   */

   public function loadBy($objField, $objValue)
   {
      /* Generate conditions array */
      foreach ($this->orm['objectFieldMap'] as $key => $value)
      {
         if (strcasecmp($objField, $key) == 0)
         {
            $conditions[] = "{$key} = {$objValue}";
         }
      }

      if (empty($conditions))
      {
         //error(E_NOTICE, "'$objField' does not map to any database field in ORM class " . get_class($this));
         return FALSE;
      }

      $ret = $this->ormLoad(ORM_THIS_OBJECT, 1, $this->ormConditions($conditions));

   return $ret;
   }



  /* Set field names in the object and automatically update the database.  Like setFields(), keys are case insensitive.
   *
   * This method only works if you have a valid tuple in the database.  In other words, you can't update a record
   * that doesn't exist yet!
   */

   function updateFields($fields)
   {
      foreach ($fields as $field => $value)
      {
         $this->set($field, $value);
      }

      if ($this->orm['dbSync'] == TRUE)
      {
         $this->save();
         return TRUE;
      }
      else
      {
         //error(E_NOTICE, "updateFields() cannot update the database because I am not synched with a database tuple yet!");
         return FALSE;
      }
   }



  /* Save (synchronize) data in object with database.  Detect if we need to INSERT or UPDATE automatically.
   *
   * Return TRUE/FALSE on success/fail.
   */

   public function save()
   {
      if ($this->orm['dbSync'] == TRUE)
      {
         return $this->ormUpdate();
      }
      else
      {
         return $this->ormSave();
      }
   }


  /* Delete a tuple/row from the database.  Speficy primary key as $id.  If no key provided, assume the current
   * object is synched/loaded and you want to remove it from the database forever.
   *
   */

   public function delete($id, $conditions=array())
   {
      if (empty($id))
      {
         $id = $this->ormGetPrimaryKeyValue();
      }

      // If we still have no id value, we can't really delete anything...
      if (empty($id)) return FALSE;

      $db = $this->orm['writer'];
      $table = $this->orm['tableNameDb'];
      $primaryKeyObj = $this->orm['primaryKey'];
      $newCond[] = "{$primaryKeyObj} = {$id}";
      $conditions = $this->ormConditions(array_merge($newCond, $conditions));
      $db->doDelete($table, $conditions);

   return TRUE;
   }


   public function cascadeDelete()
   {

   }


   ////////////////////////////////////////////////////////////////////////////////////////////////////////////
   // Find methods.  These get data from db and return either an object or array of objects, or assoc array(s).
   ////////////////////////////////////////////////////////////////////////////////////////////////////////////



  /* Basic method to get tuple(s) that match specified criteria in conditions.
   *
   * Return an object, or if resultset from database has multiple rows/tuples, return an
   * array of objects.  Force return of resultset as an array by setting retArray to TRUE,
   * even if result contains one row.
   *
   * If no results are found, return FALSE.  If retArray is set to TRUE, then empty array is
   * returned instead of FALSE.
   *
   * Magic method findAll() will always return an array of objects, and is a cleaner way of
   * achieving the same result instead of setting retArray as TRUE.
   *
   * On error return FALSE no matter what.
   */

   public function find($conditions=array(), $order=NULL, $limit=NULL, $retArray=FALSE)
   {
      $ret = $this->ormLoad(ORM_NEW_OBJECT, 0, $this->ormConditions($conditions), $order, $limit);

      if ($retArray == TRUE)
      {
         if ($ret == FALSE)
         {
            return array();
         }
      }

   return $ret;
   }


   // Execute a find statement and return the first result from the database.  This automatically puts a LIMIT clause on
   // the underlying SQL statement for you.
   //
   // @return (mixed)  Returns ORM object on success, FALSE if no record/error condition.
   public function findFirst($conditions, $order=NULL)
   {
      $ret = $this->find($conditions, $order, 1);

      if (empty($ret))
      {
         return FALSE;
      }

      if (is_array($ret))
      {
         return $ret[0];
      }
      else
      {
         return $ret;
      }
   }



  /* Shortcut to the find() method with a condition.  This method automatically generates a conditions array
   * (a WHERE clause in SQL speak) depending on the objField and objValue you specify.  You can also
   * pass additional conditions, order, and limit data if you desire.
   *
   * Example      : findBy("accountId", "100") - find all records WHERE accountId = 100
   * Magic method : findByAccountId(100)       - same.
   *
   * Returns the same stuff as find().  See comments.
   */

   public function findBy($objField, $objValue, $conditions=array(), $order=NULL, $limit=NULL, $retArray=FALSE)
   {
      /* Generate conditions array */
      foreach ($this->orm['objectFieldMap'] as $key => $value)
      {
         if (strcasecmp($objField, $key) == 0)
         {
            $conditions[] = "{$key} = {$objValue}";
         }
      }

      if (empty($conditions))
      {
         return FALSE;
      }

      $ret = $this->ormLoad(ORM_NEW_OBJECT, 0, $this->ormConditions($conditions), $order, $limit);

      if ($retArray == TRUE)
      {
         if ($ret == FALSE)
         {
            return array();
         }
      }

   return $ret;
   }



  /* Get tuple by primary key.  This should always return a single row considering that the database is
   * correctly designed with a UNIQUE key as its primary key.
   *
   * If no record can be found, return FALSE.
   */

   public function findByPrimaryKey($primaryKeyValue)
   {
      if (empty($this->orm['primaryKey']))
      {
         //error(E_WARNING, "No primary key defined, therefore I cannot find a tuple.");
         return FALSE;
      }

      /* Determine primary key for table and generate conditions array to locate 1 row in db */
      $conditions = $this->ormConditions(array("{$this->orm['primaryKey']} = {$primaryKeyValue}"));

      $ret = $this->ormLoad(ORM_NEW_OBJECT, 1, $conditions);

   return $ret;
   }



   ////////////////////////////////////////////////////////////////////////////////////////////////////////////
   // Other various public methods.
   ////////////////////////////////////////////////////////////////////////////////////////////////////////////



  /* Load relations from database.  Specify no argument to load all, or specify an array with relation names
   *
   * loadRelations(array("Employees", "Contractors")) to load Employee and Contractors relations
   */

   public function loadRelations($data = array())
   {
      global $ORMRelationLevel;
      $ORMRelationLevel = 0;

      if (empty($data))
      {
         $this->__construct();
         $this->load($this->ormGetPrimaryKeyValue());
      }

      else
      {
         $this->__construct();

         foreach (array_diff_key($this->orm['relations'], array_flip($data)) as $class => $value)
         {
            unset($this->orm['relations'][$class]);
            unset($this->orm['classes'][$class]);
            unset($this->$class);
         }

         $this->load($this->ormGetPrimaryKeyValue());
      }

   return TRUE;
   }



  /* Return the total amount of rows in the table.  This essentially performs a COUNT() call on the primary
   * key of the table (ASSUMING it is properly indexed for speed) and returns the amount.  Useful for pagination.
   *
   * It would probably be faster to somehow implement SQL_CALC_FOUND_ROWS in the future for MySQL?
   */

   public function countTableRows()
   {
      if (empty($this->orm['primaryKey']))
      {
         //error(E_WARNING, "No primary key defined in my schema.  countTableRows() requires this to be defined.");
         return FALSE;
      }

      $db = $this->orm['reader'];
      $table = $this->orm['tableNameDb'];
      $primaryKeyObj = $this->orm['primaryKey'];
      $primaryKeyDb = $this->orm['objectFieldMap'][$primaryKeyObj];

      $db->query("SELECT COUNT({$primaryKeyDb}) AS total FROM {$table}");
      $total = $db->fetchItem("total");

   return $total;
   }



  /* Return the total amount of rows in your resultset if you were to NOT INCLUDE A LIMIT CLAUSE.
   *
   * Ex:
   *   - You findAll() with a LIMIT of 0, 100, which returns 100 results.
   *   - The table really has 5,000 rows.
   *   - getTotalRows() will return 5,000, which is the total of the resultset of the last call to find
   *   - Now you have resukts 0-100 of 5,000 total.
   *
   */

   function countTotalRows()
   {
      if (empty($this->orm['primaryKey']))
      {
         //error(E_WARNING, "No primary key defined in my schema.  countTotalRows() requires this to be defined.");
         return FALSE;
      }

      $db = $this->orm['reader'];
      $table = $this->orm['tableNameDb'];
      $primaryKeyObj = $this->orm['primaryKey'];
      $primaryKeyDb = $this->orm['objectFieldMap'][$primaryKeyObj];

      // Generate WHERE clause from previous conditions array and perform the COUNT on the primary key
      // to return the total amount of rows for that previous query.
      $where = $db->generateWhere($this->orm['lastConditions']);

      $db->query("SELECT COUNT({$primaryKeyDb}) AS total FROM {$table} {$where}");
      $total = $db->fetchItem("total");

   return $total;
   }
   

   // Determine if the ORM object has any modifications that have not been synched up with the database.
   // @return (boolean) TRUE if dirty data exists, otherwise FALSE
   public function isDirty()
   {
      return $this->orm['isDirty'];
   }


   // Determine if the ORM object has synched to the database.  Use this to determine if a load() call is successful.
   // Note that you must use isDirty() to detect local modifications to the object data.  Once initially synched, then
   // this will always return TRUE.
   //
   // @return (boolean) TRUE if object successful synched to db, otherwise FALSE.
   public function isSynched()
   {
      return $this->orm['dbSync'];
   }


   // Set the table db name that will be used in the SQL statements.  If for some reason you need to reference
   // a db table that doesn't match the name of the class, you call this method to tell ORM to use the specified
   // table instead.
   //
   // Example:  If you have a class called "Customer" but you need to reference the table like this: "mydatabase.Customer" 
   //
   // @param (string) $table   Table name.
   public function setTableDbName($dbTable)
   {
      $this->orm['tableNameDb'] = $dbTable;
      $this->reMap();
   }


   // Get the database table name.  This will usually match the class name unless otherwise set to something else with
   // the setTableDbName() method.
   // @return (string) The database table
   public function getTableDbName()
   {
      return $this->orm['tableNameDb'];
   }

   
   // Get the table name.  This will always match the class name, even if the actual database table has been set to something
   // else.  We need it to match the class name when converting SQL result sets to class data.
   // @return (string)  table name
   public function getTableName()
   {
      return $this->orm['tableName'];
   }
   
   
   // Magic method broker.  This makes the loadByN, findN, findByN, getN and setN methods work.
   // @param (string) $method   The name of the method called.
   // @param (Array)  $args     Arguments passed to the method.
   public function __call($method, $args)
   {
      // loadByX where X is a fieldObjName
      if (preg_match("/^loadBy(.+?)$/", $method, $matches))
      {
         /* First argument is value of field.  It is required */
         if (!isset($args[0]))
         {
            //error(E_WARNING, "Magic loadBy() method expects the field name.");
            return FALSE;
         }

         /* Default values to pass */
         if (!isset($args[0]))
         {
            //error(E_WARNING, "Magic loadBy() methods expects the field value to be passed as the first argument.");
         }

         /* Call loadBy() method with appropriate arguments */
         return $this->loadBy($matches[1], $args[0]);
      }


      // findAllByX where X is a fieldObjName
      else if (preg_match("/^findAllBy(.+?)$/", $method, $matches))
      {
         /* First argument is value of field.  It is required */
         if (!isset($args[0]))
         {
            //error(E_WARNING, "Magic findAllBy() method expects the field value as the first argument");
            return FALSE;
         }

         /* Default values to pass */
         if (!isset($args[1])) $args[1] = array();
         if (!isset($args[2])) $args[2] = NULL;
         if (!isset($args[3])) $args[3] = NULL;

         /* Call find() method with appropriate arguments */
         return $this->findBy($matches[1], $args[0], $args[1], $args[2], $args[3], TRUE);
      }


      // findByX where X is a fieldObjName
      else if (preg_match("/^findBy(.+?)$/", $method, $matches))
      {
         /* First argument is value of field.  It is required */
         if (!isset($args[0]))
         {
            //error(E_WARNING, "Magic findBy() method expects the field value as the first argument");
            return FALSE;
         }

         /* Default values to pass */
         if (!isset($args[1])) $args[1] = array();
         if (!isset($args[2])) $args[2] = NULL;
         if (!isset($args[3])) $args[3] = NULL;

         /* Call find() method with appropriate arguments */
         return $this->findBy($matches[1], $args[0], $args[1], $args[2], $args[3]);
      }


      // findAll forces find to return an array of objects.
      else if (preg_match("/^findAll*$/", $method, $matches))
      {
         /* Default values to pass */
         if (!isset($args[0])) $args[0] = array();
         if (!isset($args[1])) $args[1] = NULL;
         if (!isset($args[2])) $args[2] = NULL;

         return $this->find($args[0], $args[1], $args[2], TRUE);
      }

      // NOTE:  There might be some conflict here, since we have some hard coded methods that start with
      // the word "get".  For now, all we have this is nice little note.

      // setX where X is a fieldObjName
      else if (preg_match("/^set(.+)$/", $method, $matches))
      {
         // setName("Craig") results in set("Name", "Craig")
         if (!isset($args[0]))
         {
            //error(E_WARNING, "Magic set() method expects the field value as the first argument");
            return FALSE;
         }

         $fieldNameObj = $matches[1];
         return $this->set($matches[1], $args[0]);
      }


      // getX where X is a fieldObjName
      else if (preg_match("/^get(.+)$/", $method, $matches))
      {
         // getName()  would return the value of $this->name;
         return $this->get($matches[1]);
      }


      else
      {
         //error(E_ERROR, "ORM picked up magic '{$method}()', but it is not defined.");
      }


   /* end __call method */
   }



   ////////////////////////////////////////////////////////////////////////////////////////////////////////////
   // ORM protected and private methods for internal use.  Herein ends the public interface.
   // NOTE: Some methods are public because they sometimes are called from other separate orm objects for the
   // purpose of building relationships/associations.
   ////////////////////////////////////////////////////////////////////////////////////////////////////////////



   // Add a mapping between an object field and a database field.  This is designed to be called from your ORM object's constructor.
   // @param (string) $objectField   The name of your object's variable
   // @param (string) $dbField       The database field name equivalent. 
   protected function addMap($objectField, $dbField)
   {
      $key = "{$this->orm['tableNameDb']}.{$dbField}";
      $this->orm['selectMap'][$key] = $this->orm['tableName'] . "_" .$objectField;
      $this->orm['fieldMap'][$dbField] = $objectField;
      $this->orm['objectFieldMap'][$objectField] = $this->orm['tableNameDb'] . "." . $dbField;
      $this->orm['insertUpdateMap'][$objectField] = $dbField;

      // Set the class property with a null value.  Also need to unset the dirty flag because of the way we're doing object setting.
      $this->$objectField = null;
      $this->orm['isDirty'] = false;
   }


   // Do a re-mapping of object to database fields.
   protected function reMap()
   {
      // Save original map
      $originalMap = $this->orm['fieldMap'];

      // Clear orm maps
      $this->orm['selectMap'] = array();
      $this->orm['fieldMap'] = array();
      $this->orm['objectFieldMap'] = array();
      $this->orm['insertUpdateMap'] = array();
   
      foreach ($originalMap as $dbField => $objField)
      {
         $this->addMap($objField, $dbField);   
      }
   }


   protected function addPrimaryKey($objectField)
   {
      $this->orm['primaryKey'] = $objectField;
   }

   
   // Adds a relationship/association to an ORM model.
   //
   // @param (constant) $relation_type   One of the defined ORM relation definitions.
   // @param (string)   $class_name      The name of the related ORM class.
   // @param (string)   $linker          Depending on relation type, could be foreign key, or link table.
   // @return (boolean) TRUE/FALSE
   protected function addRelation($relation_type, $class_name, $linker)
   {
      $short_class_name = $this->getShortClassName($class_name);
      
      //$short_class_name = $this->getShortClassName($class_name);
      if ($this->orm['loadRelations'] == FALSE) return TRUE;
      $r = array();

      switch ($relation_type)
      {
         case ORM_HAS_ONE:
           $r['type'] = $relation_type;
           $r['key'] = $linker; // foreign key
           $this->orm['relations'][$class_name] = $r;
           $this->orm['classes'][$class_name] = new $class_name(FALSE);
           $this->{$short_class_name} = $this->orm['classes'][$class_name];
         break;

         case ORM_MIGHT_HAVE_ONE:
           $r['type'] = $relation_type;
           $r['key'] = $linker; // foreign key
           $this->orm['relations'][$class_name] = $r;
           $this->orm['classes'][$class_name] = new $class_name(FALSE);
           $this->{$short_class_name} = $this->orm['classes'][$class_name];
         break;
         
         case ORM_HAS_MANY:
           $relationMap[$class_name] = TRUE;
           $r['type'] = $relation_type;
           $r['key'] = $linker; // foreign key
           $this->orm['relations'][$class_name] = $r;
           $this->orm['classes'][$class_name] = new $class_name(FALSE);
           $this->{$short_class_name} = array();
         break;

         case ORM_BELONGS_TO:
           $relationMap[$class_name] = TRUE;
           $r['type'] = $relation_type;
           $r['key'] = $linker; // foreign key
           $this->orm['relations'][$class_name] = $r;
           $this->orm['classes'][$class_name] = new $class_name(FALSE);
           $this->{$short_class_name} = $this->orm['classes'][$class_name];
         break;
         
         case ORM_HAS_AND_BELONGS_TO_MANY:
           $relationMap[$class_name] = TRUE;
           $r['type'] = $relation_type;
           $r['linkTable'] = $linker;
           $this->orm['relations'][$class_name] = $r;
           $this->orm['classes'][$class_name] = new $class_name(FALSE);
           $this->{$short_class_name} = array();
         break;
         
         default:
            return FALSE;
         break;
      }

   return TRUE;
   }


   // Set the cascade save setting to true or false for enable/disable
   protected function setCascadeSave($val=false)
   {
      $this->orm['cascadeSave'] = $val;
   }

   
   // Set the cascade delete setting to true or false for enable/disable
   protected function setCascadeDelete($val=false)
   {
      $this->orm['cascadeDelete'] = $val;
   }
   
   
  /* Add a Unique field restraint to field(s).  This will cause ORM to use the doUniqueInsert() method
   * when making calls to insert data to the database.
   *
   * At some point, this could also perform checks before ever talking to the db.
   */

   protected function addUnique($fields)
   {
      $this->orm['unique'] = TRUE;
   }


   // Return the current primary key value in the object.  This is a public function because the ORM code has so
   // reference extrnal objects to manage relationships, and if the method was protected, this would throw errors.
   public function ormGetPrimaryKeyValue()
   {
      $p = $this->orm['primaryKey'];

      if (isset($this->$p))
      {
         return $this->$p;
      }
      else
      {
         return NULL;
      }
   }


   // Return the primary key field name as defined in the object. Needs to be a public function so that other
   // orm objects can call this method when needed.
   // @return (string) field name
   public function ormGetPrimaryKeyField()
   {
      return $this->orm['primaryKey'];
   }


   // Return the primary key field name as defined in the database.  Needs to be a public function so that other
   // orm objects can call this method when needed.
   // @return (string) field name
   public function ormGetPrimaryKeyDbField()
   {
      $primaryKey = $this->orm['primaryKey'];
      list($table, $dbKey) = explode(".", $this->orm['objectFieldMap'][$primaryKey]);
      return $dbKey;
   }


   // Check if the current ORM object has any relations defined.
   // @return (boolean)  TRUE if relations are defined, FALSE otherwise.
   private function ormHasRelations()
   {
      if (count($this->orm['relations']) > 0)
      {
         return TRUE;
      }
      else
      {
         return FALSE;
      }
   }


  /* Save object data to database, performing the initial synchronization.
   *
   * Need to do a better job for AUTO_INCREMENT primary keys.  What about SEQUENCES??
   */

   public function ormSave()
   {
      if (method_exists($this, "onBeforeSave"))
      {
         $this->onBeforeSave();
      }
      
      if (empty($this->orm['primaryKey']))
      {
         //error(E_WARNING, "No primary key defined, therefore I cannot save a tuple in the database.");
         return FALSE;
      }

      foreach ($this->orm['insertUpdateMap'] as $objVar => $dbVar)
      {
         $fields[$dbVar] = $this->{$objVar};
      }

      if ($this->orm['unique'] == TRUE)
        $iMethod = "doUniqueInsert";
      else
        $iMethod = "doInsert";

      if (!$this->orm['writer']->{$iMethod}($this->orm['tableNameDb'], $fields))
        return FALSE;

      /* Right now this assumes auto_incrementing primary keys in MySQL only.  If the primary key is empty, attempt to get it from the db. */
      $primaryKey = $this->orm['primaryKey'];
      if (empty($this->{$primaryKey})) $this->{$primaryKey} = $this->orm['writer']->lastInsertId();

      $this->orm['dbSync'] = TRUE;
      $this->orm['isDirty'] = FALSE;
      
      if ($this->orm['cascadeSave'] == true)
      {
         $this->ormCascadeSave();
      }
      
      if (method_exists($this, "onAfterSave"))
      {
         $this->onAfterSave();
      }

   return TRUE;
   }



   // Cascading save.  We have two scenarios here that we want to take into account when saving objects for the first time.
   // A) Objects that are related using INNER JOINS (Belongs to, HAS ONE) will be saved, even with empty records.  The
   //    relationship dictates that there should always be a related object of this type, so it gets inserted no matter what.
   // B) Objects that are related using a LEFT JOIN (Might have one, has many) will only get saved if the related object is
   //    dirty, meaning that it has been modified and needs to save.  Otherwise, any related objects will get skipped.
   private function ormCascadeSave()
   {
      foreach ($this->orm['relations'] as $class => $relDetail)
      {	 
	 // Make sure the related object is available.  It should always be.
	 if (!isset($this->$class))
	 {
	    continue;
	 }
	 
	 // $ref will be a pointer to the related object.	 
	 $ref = $this->$class;

	 if ($ref->isDirty() || $relDetail['type'] == ORM_HAS_ONE || $relDetail['type'] == ORM_BELONGS_TO)
	 {
            // Is key in format of localkey:foreignKey?
            if (strpos($relDetail['key'], ":", 1))
            {
               list($localKey, $foreignKey) = explode(":", $relDetail['key']);
	    }
	    // Otherwise we assume the srting represents the key variable on both objects.
	    else
	    {
	       $localKey = $foreignKey = $relDetail['key'];  
	    }
	 
	    // Get value of local key, and set foreign key with this value.  Sync the related object to the db.
	    $ref->set($foreignKey, $this->get($localKey));
	    $ref->ormSave();   
	 }
      }      
   }


  /* Update object data in database using primary key.
   */

   public function ormUpdate()
   {
      if (method_exists($this, "onBeforeUpdate"))
      {
         $this->onBeforeUpdate();
      }
      
      $pkv = $this->ormGetPrimaryKeyValue();

      if (empty($pkv))
      {
         //error(E_WARNING, "ormUpdate() found an empty primary key in the object.  Impossible to update.");
         return FALSE;
      }

      foreach ($this->orm['insertUpdateMap'] as $objVar => $dbVar)
      {        
         $fields[$dbVar] = $this->$objVar;
      }

      $conditions = $this->ormConditions(array("{$this->orm['primaryKey']} = {$pkv}"));
      
      if (!$this->orm['writer']->doUpdate($this->orm['tableNameDb'], $fields, $conditions))
      {
        return FALSE;
      }

      if ($this->orm['cascadeSave'] == true)
      {
	 $this->ormCascadeUpdate();
      }
      
      if (method_exists($this, "onAfterUpdate"))
      {
         $this->onAfterUpdate();
      }

   return TRUE;
   }
   


   // Cascading update. Loop through relations, and trigger an update on any objects that have the dirty flag
   // set, syncing changes with the database for that object.
   private function ormCascadeUpdate()
   {
      foreach ($this->orm['relations'] as $class => $relDetail)
      {	 
	 // Make sure the related object is available.  It should always be.
	 if (!isset($this->$class))
	 {
	    continue;
	 }
	 
	 // $ref will be a pointer to the related object.	 
	 $ref = $this->$class;

	 if ($ref->isDirty())
	 {
	    $ref->ormUpdate();      
	 }
      }
   }
   
   
   
   // Start the data loading process.  Build and execute SQL query / queries based on the object maps
   // and relationships defined in the ORM object. This is pretty complex stuff.
   //
   // @return (array)  Returns an array of ORM objects constructed from the SQL resultset. 
   private function ormLoad($type=ORM_THIS_OBJECT, $expectedRows=1, $conditions, $order=NULL, $limit=NULL)
   {
      $hasMany = 0;

      // Call the beforeload callback in the current instance.  Make sure your callback defines the function arguments
      // to be passed BY REFERENCE so you can modify them as needed!
      if (method_exists($this, "onBeforeLoad"))
      {
         // If we detect a FALSE return value, stop the load process.
         if ($this->onBeforeLoad($type, $conditions, $order, $limit) === FALSE)
         {
            return array();
         }
      }
      
      // Define the primary query now to get the minimal from the database.  Subsequent call to parimaryQuery() will define
      // any JOINS for that query.  Subsequent Has many joins will go into subsequent queries.  See code/comments below.
      $this->primaryQuery(array($this->orm['tableNameDb']), $this->orm['selectMap'], $conditions, $order, $limit);


      // Iterate through any relations to define any JOINS and additional queries...
      foreach ($this->orm['relations'] as $class => $relDetail)
      {
         // We add to the primary query by making JOINS for every relationship of this type.
         if ($relDetail['type'] == ORM_HAS_ONE || $relDetail['type'] == ORM_BELONGS_TO || $relDetail['type'] == ORM_MIGHT_HAVE_ONE)
         {
            $fields = $this->orm['selectMap'];
            $classRef = $this->orm['classes'][$class];
            $foreignTable = $classRef->orm['tableNameDb'];
            $expectedRows = 0;

            // Might have one signifies that there is a possible 1 to 1 mapping, but it is possible that the row in the foreign table does not exist.
            if ($relDetail['type'] == ORM_MIGHT_HAVE_ONE)
            {
               $joinType = "LEFT JOIN";
            }
            else
            {
               $joinType = "INNER JOIN";
            }

            // Is key in format of localkey:foreignKey?
            if (strpos($relDetail['key'], ":", 1))
            {
               list($localKey, $foreignKey) = explode(":", $relDetail['key']);

               // Get local table and key from map
               $fieldArrLocal = explode(".", $this->orm['objectFieldMap'][$localKey]);
               $localKeyDb = array_pop($fieldArrLocal);
               $localTable = implode(".", $fieldArrLocal); 

               // Get foreign table and key from map
               $fieldArrForeign = explode(".", $classRef->orm['objectFieldMap'][$foreignKey]);
               $foreignKeyDb = array_pop($fieldArrForeign);
               $foreignTable = implode(".", $fieldArrForeign); 

               //list($localTable, $localKeyDb) = explode(".", $this->orm['objectFieldMap'][$localKey]);
               //list($foreignTable, $foreignKeyDb) = explode(".", $classRef->orm['objectFieldMap'][$foreignKey]);
               $tables[$foreignTable] = "LEFT JOIN {$localTable}.{$localKeyDb} = {$foreignTable}.{$foreignKeyDb}";               
            }
            // Key is the same on both objects
            else
            {
               $fieldArr = explode(".", $this->orm['objectFieldMap'][$relDetail['key']]);
               $foreignKey = array_pop($fieldArr);
               $localTable = implode(".", $fieldArr); 
               //list($localTable, $foreignKey) = explode(".", $this->orm['objectFieldMap'][$relDetail['key']], 2);
               $tables[$foreignTable] = "{$joinType} {$localTable}.{$foreignKey} = {$foreignTable}.{$foreignKey}";
            }

            $fields = array_merge($fields, $classRef->orm['selectMap']);
            
            // add to primary query queue
            $this->primaryQuery($tables, $fields);
         }

         // HAS MANY relationships are a little bit more complex.  See below.
         else if ($relDetail['type'] == ORM_HAS_MANY)
         {
            $fields = $this->orm['selectMap'];
            $classRef = $this->orm['classes'][$class];
            $foreignTable = $classRef->orm['tableNameDb'];
            $expectedRows = 0; // We probably will have multiple rows with this relation

            // Is key in format of localkey:foreignKey?
            if (strpos($relDetail['key'], ":", 1))
            {
               list($localKey, $foreignKey) = explode(":", $relDetail['key']);

               // Get local table and key from map
               $fieldArrLocal = explode(".", $this->orm['objectFieldMap'][$localKey]);
               $localKeyDb = array_pop($fieldArrLocal);
               $localTable = implode(".", $fieldArrLocal); 

               // Get foreign table and key from map
               $fieldArrForeign = explode(".", $classRef->orm['objectFieldMap'][$foreignKey]);
               $foreignKeyDb = array_pop($fieldArrForeign);
               $foreignTable = implode(".", $fieldArrForeign); 

               //list($localTable, $localKeyDb) = explode(".", $this->orm['objectFieldMap'][$localKey]);
               //list($foreignTable, $foreignKeyDb) = explode(".", $classRef->orm['objectFieldMap'][$foreignKey]);
               $tables[$foreignTable] = "LEFT JOIN {$localTable}.{$localKeyDb} = {$foreignTable}.{$foreignKeyDb}";               
            }
            // Key is the same on both objects
            else
            {
               $fieldArr = explode(".", $this->orm['objectFieldMap'][$relDetail['key']]);
               $foreignKey = array_pop($fieldArr);
               $localTable = implode(".", $fieldArr); 
               //list($localTable, $foreignKey) = explode(".", $this->orm['objectFieldMap'][$relDetail['key']]);
               $tables[$foreignTable] = "LEFT JOIN {$localTable}.{$foreignKey} = {$foreignTable}.{$foreignKey}";
            }

            $fields = array_merge($fields, $classRef->orm['selectMap']);
            
            // We can only have a single HAS_MANY (SQL LEFT JOIN) in our primary query.  If we have additional HAS_MANY
            // relationships, we need to make separate queries for each of those.
            if ($hasMany == 0)
            {
               $this->primaryQuery($tables, $fields);
               $hasMany++;
            }
            else
            {
               // need to pass in the primary table name as well as the conditions for the new query...
               $tables[0] = $this->orm['tableNameDb'];
               $this->additionalQuery($tables, $fields, $conditions, $order, $limit);               
               $hasMany++;
            }
         }
         
         // Has many....
         else if ($relDetail['type'] == ORM_HAS_AND_BELONGS_TO_MANY)
         {
            $fields = $this->orm['selectMap'];
            $classRef = $this->orm['classes'][$class];
            $localTable = $this->getTableDbName();
            $foreignTable = $classRef->getTableDbName();
            $linkTable = $relDetail['linkTable']; 
            $expectedRows = 0; // We probably will have multiple rows with this relation

            $primaryKey = $this->ormGetPrimaryKeyField();
            $primaryKeyDb = $this->ormGetPrimaryKeyDbField();
            $foreignKey = $classRef->ormGetPrimaryKeyField();
            $foreignKeyDb = $classRef->ormGetPrimaryKeyDbField();
            $fields = array_merge($fields, $classRef->orm['selectMap']);

            // Set this to that the tuple conversion that happens after this works.
            //$this->orm['relations'][$class]['key'] = $foreignKey;

            // First JOIN the link table based on our primary key.            
            $tables[$linkTable] = "LEFT JOIN {$localTable}.{$primaryKeyDb} = {$linkTable}.{$primaryKeyDb}";

            // Secondly JOIN the foreign table based on the other key in the link table, which is the primary key of the foreign table.
            $tables[$foreignTable] = "LEFT JOIN {$linkTable}.{$foreignKeyDb} = {$foreignTable}.{$foreignKeyDb}";

            // We can only have a single HAS_MANY (SQL LEFT JOIN) in our primary query.  If we have additional HAS_MANY
            // relationships, we need to make separate queries for each of those.
            if ($hasMany == 0)
            {
               $this->primaryQuery($tables, $fields);
               $hasMany++;
            }
            else
            {
               // need to pass in the primary table name as well as the conditions for the new query...
               $tables[0] = $this->orm['tableNameDb'];
               $this->additionalQuery($tables, $fields, $conditions, $order, $limit);               
               $hasMany++;
            }
         }
         
         // reset some values
         $tables = array();
         $fields = array();
         $order = "";
         $limit = "";
      
      // end query build foreach loop
      }

      // Query the db and create the related object(s)
      $ret = $this->ormConvertTuplesToObjects($type);
      
      // Call the afterload callback if it exists in the current instance.
      if (method_exists($this, "onAfterLoad"))
      {
         $this->onAfterLoad($type);
      }
      
      // If a find() variant has been called, call the method on the INITIAL ORM object created
      // NOTE: This is NOT called on any of the returned ORM objects. ALSO make sure you define
      // the function variables as REFERENCE variables.
      if (method_exists($this, "onAfterFind") && $type == ORM_NEW_OBJECT)
      {
         $this->onAfterFind($type, $conditions, $order, $limit, $ret);
      }
      
      // Empty the query queue.
      $this->orm['queryQueue'] = array();

      // We could return TRUE/FALSE for a load() method, or data for a find() method...      
      return $ret;
   }



   // Convert tuples to objects.  Once the queries are built and executed with ormLoad(), execution is handed
   // off to us to actually convert the SQL flat resultset into a series of objects.
   // @return (array)   Array of objects created for resultset, or TRUE if loading locally.
   private function ormConvertTuplesToObjects($type=ORM_THIS_OBJECT)
   {
      $objects = array();
      $index = array();
      
      // Iterate through the query queue, executing each db query and converting each resulset...
      foreach ($this->orm['queryQueue'] as $queue)
      {
         if (!$this->orm['reader']->doSelect($queue['tables'], $queue['fields'], $queue['conditions'], $queue['order'], $queue['limit']))
         {
            return FALSE;
         }
	 
         $numRows = $this->orm['reader']->numRows();
	 
         if ($numRows == 0)
         {
            return FALSE;
         }
	 
         // Set data locally for the load() methods in current object.
         if ($type == ORM_THIS_OBJECT)
         {
            while ($row = $this->orm['reader']->fetchArray())
            {
               $this->ormConvertTupleThis($type, $row);   
            }
   
            // Just return TRUE
            $objects = TRUE;
         }

         // Make new object(s) and return them for the various find() methods.  Store each object in an index array
         // so that we can go back and populate any relation data into the object as we iterate through the different resultsets.
         if ($type == ORM_NEW_OBJECT)
         {
            while ($row = $this->orm['reader']->fetchArray())
            {
               $key = $this->getTableName() . "_" . $this->ormGetPrimaryKeyField();
               $uniqueValue = $row[$key];
      
               if (isset($index[$uniqueValue]))
               {
                  $index[$uniqueValue] = $this->ormConvertTupleNew($type, $row, $index[$uniqueValue]);
               }
               else
               {
                  $index[$uniqueValue] = $this->ormConvertTupleNew($type, $row);
                  $objects[] = $index[$uniqueValue];
               }
            }
         }

      // end foreach
      }
      
      unset($index); // just in case?
      return $objects;
   }


   // This method sets the data in the current object with the resultset.  This happens when the user
   // calls a load() method, which is much easier than the find() methods.  Since JOINS have duplicate
   // results, we wind up setting the data in $this multiple times because of the necessary iterations
   // that happen to set the related data.  It's all good though.
   //
   // @param $type       (int)    The current operation type for current object or new object
   // @param $resultset  (Array)  The resultset of the select query just executed.
   // @return (boolean)  Though it doesn't mean very much.
   private function ormConvertTupleThis($type, $resultset)
   {
      $className = strtolower(get_class($this));
      $shortClassName = $this->getShortClassName($className);

      // Set the values on the local object
      foreach ($resultset as $key => $value)
      {
         list($resClass, $resParam) = explode("_", $key, 2);
         if (strtolower($resClass) == $shortClassName) $this->$resParam = $value;
      }

      $this->orm['dbSync'] = TRUE;

      // Set values in related objects in $this
      foreach ($this->orm['relations'] as $class => $detail)
      {
         $shortClass = $this->getShortClassName($class);
         
         if ($detail['type'] == ORM_HAS_ONE || $detail['type'] == ORM_BELONGS_TO || $detail['type'] == ORM_MIGHT_HAVE_ONE)
         {
            $objRef = $this->{$shortClass};
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === FALSE) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);
            $this->setForeignKeyValue($detail, $objRef);
            $objRef->orm['dbSync'] = TRUE;            
            if (method_exists($objRef, "onAfterLoad")) $objRef->onAfterLoad($type);
            unset($objRef);
         }

         if ($detail['type'] == ORM_HAS_MANY)
         {
            $objRef = new $class;
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === FALSE) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);
            $this->setForeignKeyValue($detail, $objRef);
            $objRef->orm['dbSync'] = TRUE;
            if ($numFields > 0) $this->orm['data'][$shortClass][] = $objRef;            
            if (method_exists($objRef, "onAfterLoad")) $objRef->onAfterLoad($type);
            //unset($objRef);
         }

         if ($detail['type'] == ORM_HAS_AND_BELONGS_TO_MANY)
         {
            $objRef = new $class;
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === FALSE) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);
            $this->setForeignKeyValue($detail, $objRef);
            $objRef->orm['dbSync'] = TRUE;
            if ($numFields > 0) $this->orm['data'][$shortClass][] = $objRef;            
            if (method_exists($objRef, "onAfterLoad")) $objRef->onAfterLoad($type);
            unset($objRef);
         }
      }

   return $this;
   }


   // Convert tuples into objects.  This is performed when the user uses a find method, so we have
   // to create a resultset of new objects.
   //
   // if $newObj is null, create a new ORM object and set the fields.  If it is passed in, worry about
   // setting any related object data.
   //
   // @param $type       (int)    The current operation type for current object or new object
   // @param $resultset  (Array)  The resultset of the select query just executed.
   // @param $newObj     (ORM)  Pass the ORM object in that we are working with if needed
   // @return (ORM)    Return the ORM object with data set.
   private function ormConvertTupleNew($type, $resultset, $newObj=null)
   {
      $className = strtolower(get_class($this));
      $shortClassName = $this->getShortClassName($className);

      if (method_exists($newObj, "onBeforeLoad")) { if ($newObj->onBeforeLoad($type) === FALSE) continue; }

      // Create the new object and populate the object variables with data.  We only do this once.
      if ($newObj == null)
      {
         $newObj = new $className;
         $newObj->setTableDbName($this->getTableDbName());
         $newObj->orm['dbSync'] = TRUE;
         
         // Set values in newObj
         foreach ($resultset as $key => $value)
         {
            list($resClass, $resParam) = explode("_", $key, 2);
            if (strtolower($resClass) == strtolower($this->getShortClassName(get_class($newObj)))) $newObj->{$resParam} = $value;
         }         
      }

      // Set values in related objects in newObj
      foreach ($newObj->orm['relations'] as $class => $detail)
      {
         $shortClass = $this->getShortClassName($class);
         
         if ($detail['type'] == ORM_HAS_ONE || $detail['type'] == ORM_BELONGS_TO || $detail['type'] == ORM_MIGHT_HAVE_ONE)
         {
            $objRef = new $class;
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === FALSE) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);
            $newObj->setForeignKeyValue($detail, $objRef);
            if ($numFields > 0) $newObj->{$shortClass} = $objRef;
            if (method_exists($objRef, "onAfterLoad")) $objRef->onAfterLoad($type);
         }

         if ($detail['type'] == ORM_HAS_MANY)
         {
            $objRef = new $class;
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === FALSE) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);
            $newObj->setForeignKeyValue($detail, $objRef);
            if ($numFields > 0)
            {
               // We need to get a reference, modify it, then re-set it.  I think it has to do with __set() being employed.
               // Hopefully this doesn't create any memory leaks...
               $ref = $newObj->{$shortClass};
               $ref[] = $objRef;
               $newObj->{$shortClass} = $ref;
            }
            
            if (method_exists($objRef, "onAfterLoad")) $objRef->onAfterLoad($type);
            unset($objRef);
         }

         if ($detail['type'] == ORM_HAS_AND_BELONGS_TO_MANY)
         {
            $objRef = new $class;
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === FALSE) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);
            $newObj->setForeignKeyValue($detail, $objRef);
            if ($numFields > 0)
            {
               // We need to get a reference, modify it, then re-set it.  I think it has to do with __set() being employed.
               // Hopefully this doesn't create any memory leaks...
               $ref = $newObj->{$shortClass};
               $ref[] = $objRef;
               $newObj->{$shortClass} = $ref;
            }

            if (method_exists($objRef, "onAfterLoad")) $objRef->onAfterLoad($type);
            unset($objRef);
         }
      }

      // Since we have created a new object (presumably due to using a find method), now call the hook on the new object.
      if (method_exists($newObj, "onAfterLoad"))
      {
         $newObj->onAfterLoad($type);
      }

   return $newObj;
   }


   // Add/Modify the primary query paramaters.
   //
   // NOTE: Be sure to only pass conditions one time.  array_merge() won't properly merge duplicate conditions because they
   // are numerically indexed...
   private function primaryQuery($tables, $fields=array(), $conditions=array(), $order=NULL, $limit=NULL)
   {
      $position = 0; // position (array index) 0 is the primary query
      
      if (!isset($this->orm['queryQueue'][$position]))
      {
         $this->orm['queryQueue'][$position] = array();
         $this->orm['queryQueue'][$position]['tables'] = array();
         $this->orm['queryQueue'][$position]['fields'] = array();
         $this->orm['queryQueue'][$position]['conditions'] = array();         
         $this->orm['queryQueue'][$position]['order'] = $order;         
         $this->orm['queryQueue'][$position]['limit'] = $limit;         
      }
      
      $this->orm['queryQueue'][$position]['tables'] = array_merge($this->orm['queryQueue'][$position]['tables'], $tables);
      $this->orm['queryQueue'][$position]['fields'] = array_merge($this->orm['queryQueue'][$position]['fields'], $fields);

      // If $conditions contains literal WHERE clause, don't touch it.
      if (is_string($conditions))
      {
         $this->orm['queryQueue'][$position]['conditions'] = $conditions;         
      }
      else
      {
         $this->orm['queryQueue'][$position]['conditions'] = array_merge($this->orm['queryQueue'][$position]['conditions'], $conditions);                  
      }

   }


   // Add an additional query to the db query queue.  Pass in the normal arguments required for the doSelect() method...
   private function additionalQuery($tables, $fields, $conditions, $order, $limit)
   {
         $newQueue['tables'] = $tables;
         $newQueue['fields'] = $fields;
         $newQueue['conditions'] = $conditions;
         $newQueue['order'] = NULL;
         $newQueue['limit'] = NULL;
         array_push($this->orm['queryQueue'], $newQueue);
   }


   // Populate the object variables with database values.  Return the number of fields populated.
   private function ormPopulateFields($resultset, $shortClassName, $objRef)
   {
      $numFields = 0;
      
      foreach ($resultset as $key => $value)
      {
         list($resClass, $resParam) = explode("_", $key, 2);
             
         if ($resClass == $shortClassName && $value != NULL)
         {
            $objRef->{$resParam} = $value;
            $numFields++;
         }
      }

      $objRef->orm['dbSync'] = TRUE;
      return $numFields;
   }


   private function ormSetForeignKeyValue($detail, $objRef)
   {
      if (strpos($detail['key'], ":", 1))
      {
         list($localKey, $foreignKey) = explode(":", $detail['key']);
      }
      else
      {
         $foreignKey = $detail['key'];
         $localKey = $foreignKey;
      }

      list($table, $key) = explode(".", $this->orm['objectFieldMap'][$foreignKey]);
      $foreignKeyValue = $this->$localKey;

      /* Set conditions array in related object to include foreign key = local key value */
      $conditions = array();
      $conditions[] = $key . " = " . $foreignKeyValue;
      $objRef->ormSetAutoConditions($conditions);

      /* Set foreign key property in related object to my current value */
      $objKey = $objRef->orm['fieldMap'][$key];
      $objRef->$objKey = $foreignKeyValue;
   }



  /* Set the automatic conditions array that load() and various find() methods use.
   */

   function ormSetAutoConditions($conditions=array())
   {
      $this->orm['conditions'] = $conditions;
   }



  /* Convert a conditions array where object variable names are unsed into database field names
   * to pass on to the SQL query generator.
   *
   * To use a literal WHERE clause, pass in your string that contains a WHERE clause to $conditions and it will be
   * simply passed along as is to the query function.  Everything must contain database field names and not object
   * mappings if this is the case.
   *
   * @param  (mixed)  $conditions    Array of conditions like normal, except keyed on object fields and not db fields.
   * @return (array)  Returns the new condition array, ignoring any fields that do not map to the ORM object.
   */

   private function ormConditions($conditions=array())
   {
      /* Do we have a literal WHERE clause? */
      if (is_string($conditions))
      {
         return $conditions;
      }

      $newConditions = array();
      $this->orm['lastConditions'] = array();

      foreach ($conditions as $idex => $detail)
      {
         // match 'Database.Table.field <O> value'  OR  'Table.field <O> value'  OR  'field <O> value'
         // where <O> is a supported SQL operator

         if (preg_match("/^([a-zA-Z0-9-_]+?\.[a-zA-Z0-9-_]+?\.[a-zA-Z0-9-_]+?|[a-zA-Z0-9-_]+?\.[a-zA-Z0-9-_]+?|[a-zA-Z0-9-_]+?)\s{1}(.+?)\s{1}(.+)?/si", $detail, $matched))
         {
            if (isset($this->orm['objectFieldMap'][$matched[1]]) && !empty($this->orm['objectFieldMap'][$matched[1]]))
            {               
               $field = $this->orm['objectFieldMap'][$matched[1]];
               $newConditions[] = "{$field} {$matched[2]} {$matched[3]}";
            }
            else
            {
               //error(E_NOTICE, "Conditions array contained a variable '{$matched[1]}' that did not map to a database field.  Not using it.");
            }
         }
      }

      if (isset($this->orm['conditions']) && is_array($this->orm['conditions']))
      {
         $newConditions = array_merge($this->orm['conditions'], $newConditions);
      }

      // Save conditions array
      $this->orm['lastConditions'] = $newConditions;

   return $newConditions;
   }


   // Utilty function to return only the class name from a complete namespace+classname string.
   // @param string $className The class name in question.  If unspecified, get_class($this) is assumed.
   // @return string Shortened class name without namespace
   protected function getShortClassName($className = null)
   {
      if (!$className) $className = get_class($this);
      return substr(strrchr($className, "\\"), 1);
   }
   
   
/* End ORM */
}


?>
