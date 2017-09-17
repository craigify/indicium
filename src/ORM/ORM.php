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
// getRelations()             Return an array of any related loaded ORM objects in the data set.
// getDbFields()              Return array of key=>value pairs with database column names instead of object properties.
// setFields()                Use array of key=>value pairs to set object's data.
// updateFields()             Update fields with key=>value pairs.  Only update fields present in your array.
// ORM->fieldname             Access any field name with normal object notation.  e.g. ORM->name = "Craig"
// setField(value)            ORM->setName("Craig") sets ORM->name = "Craig"
// getField()                 ORM->getName returns value of ORM->name, which would be "Craig"
// isDirty()                  Return true if object data is "dirty" or not synched with the db.  save() will solve this.
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

use Indicium\Exceptions\ORMException;
use Indicium\Exceptions\InvalidStateException;

// ORM relation types
define("ORM_HAS_ONE",        1001);    // 1 to 1 mapping with INNER JOIN
define("ORM_MIGHT_HAVE_ONE", 1002);    // 1 to 1 mapping with LEFT JOIN
define("ORM_BELONGS_TO",     1003);    // 1 to 1 mapping with INNER JOIN.
define("ORM_HAS_MANY",       1007);    // 1 to many mapping
define("ORM_MANY_TO_MANY",   1009);    // many to many relationship with link table

// Other constants
define("ORM_NEW_OBJECT",     2000);
define("ORM_THIS_OBJECT",    2002);
define("ORM_ARRAY",          2004);

abstract class ORM
{
   // All ORM objects store their data in this internal array.  This is done to stop too much object pollution with ORM stuff.
   protected $orm = array();


   // Constructor.
   public function __construct()
   {
      $this->orm['data'] = array();
      $this->orm['loadRelations'] = true;
      $this->orm['tableName'] = $this->getShortClassName(); // get_class($this);
      $this->orm['tableNameDb'] = $this->getShortClassName(); // get_class($this);
      $this->orm['primaryKey'] = NULL;
      $this->orm['unique'] = false;
      $this->orm['fieldMap'] = array();
      $this->orm['objectFieldMap'] = array();
      $this->orm['conditions'] = array(); // conditions used every time.
      $this->orm['lastConditions'] = array(); // last conditions array for previous query.
      $this->orm['relations'] = array();
      $this->orm['classes'] = array();
      $this->orm['queryQueue'] = array();
      $this->orm['dbSync'] = false;
      $this->orm['isDirty'] = false;
      $this->orm['enableTransactions'] = true;
      $this->orm['cascadeSave'] = false;
      $this->orm['cascadeDelete'] = false;
      $this->orm['reader'] = NULL;
      $this->orm['writer'] = NULL;
   }
   
   
   // How to check if an objet is an ORM object:
   // method_exists($obj, "isORM") === true
   public function isORM()
   {
      return true;
   }
   

   // Tell us what QueryBuilder object to use for read operations.
   // @param object $queryBuilder An instantiated and connected QueryBuilder object
   public function setQueryBuilderReader($queryBuilder)
   {
      $this->orm['reader'] = $queryBuilder;

      foreach ($this->orm['classes'] as $obj)
      {
         $obj->setQueryBuilderReader($queryBuilder);
      }
   }


   // Tell us what QueryBuilder object to use for write operations.
   // @param object $queryBuilder An instantiated and connected QueryBuilder object
   public function setQueryBuilderWriter($queryBuilder)
   {
      $this->orm['writer'] = $queryBuilder;

      foreach ($this->orm['classes'] as $obj)
      {
         $obj->setQueryBuilderWriter($queryBuilder);
      }
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
   
   
   // Alias to set reader
   // @param object $queryBuilder An instantiated and connected QueryBuilder object
   public function setReader($queryBuilder)
   {
      $this->setQueryBuilderReader($queryBuilder);
   }
   
   
   // Alias to set writer
   // @param object $queryBuilder An instantiated and connected QueryBuilder object
   public function setWriter($queryBuilder)
   {
      $this->setQueryBuilderWriter($queryBuilder);
   }
   
   
   // Retrieve a reference to QueryBuilder object for read operations.  This is used internally,
   // but you can use it too for whatever reason you might need.
   // @param object An instantiated and connected QueryBuilder object
   public function getReader()
   {
      return $this->orm['reader'];
   }


   // Retrieve a reference to QueryBuilder object for write operations.  This is used internally,
   // but you can use it too for whatever reason you might need.
   // @param object An instantiated and connected QueryBuilder object
   public function getWriter()
   {
      return $this->orm['writer'];
   }


   // Inject a PSR-3 compatible logger.
   // @param object $logger Logger object
   public function setLogger($logger)
   {
      $this->orm['logger'] = $logger;
   }
   
   
   // @return object Reference to PSR-3 compatible logger object.
   public function getLogger()
   {
      return $this->orm['logger'];
   }

   
   // This tells ORM to use transactions where necessary.  
   public function enableTransactions()
   {
      $this->orm['enableTransactions'] = true;
   }

   
   // This tells ORM not to use transactions.  It would be up to you to begin and end a transaction
   // manually when performing database syncing operations.
   public function disableTransactions()
   {
      $this->orm['enableTransactions'] = false;
   }

   
   // Enable automatic cascade save.
   protected function enableCascadeSave()
   {
      $this->orm['cascadeSave'] = true;
   }

   
   // Disable automatic cascade save.
   protected function disableCascadeSave()
   {
      $this->orm['cascadeSave'] = false;
   }

   
   // Enable automatic loading of nested relations (default).
   public function enableNestedRelations()
   {
      $this->orm['loadRelations'] = true;
   }
   
   // Disable the automatic loading of nested relations.
   public function disableNestedRelations()
   {
      $this->orm['loadRelations'] = false;      
   }


   //////////////////////////////////////////////////////////////////////////////////////////////////
   // get, set and load methods.  Get data in and out of the current object.               
   //////////////////////////////////////////////////////////////////////////////////////////////////


   // Intercept all property sets and direct them to the set() method.  We're able to determine the
   // data state of the ORM object this way, either dirty or not.
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
   // @return mixed Returns your value back to you
   public function set($objField, $objValue)
   {      
      foreach ($this->orm['objectFieldMap'] as $key => $value)
      {
         if (strcasecmp($objField, $key) == 0)
         {
            $this->ormSet($objField, $objValue);
            return $objValue;
         }	 
      }

      // If we get here, we're setting a value that isn't in our object map.  The isDirty flag won't
      // get set and no events will be triggered.
      //
      // We need this to bere here because we set related objects in the data array, and they aren't
      // part of the object field map.  This could be changed later and store related objects
      // somewhere else internally.
      $this->orm['data'][$objField] = $objValue;

   return $objValue;
   }
   
   
   // Set data on the ORM object as well as data on any related orm objects.  The fields data can be
   // a simple object with properties, or an assoc array of key=>value pairs.
   //
   // Related objects should be represented by their short class name, and contain either an array
   // of objects (or assoc arrays) with keys for has many relationships, or just a singular object
   // or assoc array with keys for a 1=1 relationship.
   //
   // @throws InvalidStateException if the input field data contains related object data that does
   //         not match the relation definitions
   //
   // @throws ORMException for all other errors
   //
   // @param mixed $fields Field data to set
   public function setFields($fields)
   {
      if ((!is_array($fields) && !$this->isAssoc($fields)) && !is_object($fields))
      {
         throw new ORMException("fields is supposed to be either an associative array of key=value pairs, or an object with properties.");
      }

      if (is_object($fields))
      {
         $fields = (array)$fields;
      }
      
      foreach ($fields as $fieldKey => $fieldValue)
      {
         // If key is a field name, set it on $this. Easy.
         if (array_key_exists($fieldKey, $this->orm['objectFieldMap']))
         {
            $this->ormSet($fieldKey, $fieldValue);
            continue;
         }
                  
         // If key is a related object, we first need to find the matching related object by iterating
         // through our relations array until we find a match.
         foreach ($this->orm['relations'] as $longClassName => $relation)
         {            
            $shortClassName = $this->getShortClassName($longClassName);
            
            if ($fieldKey == $longClassName || $fieldKey == $shortClassName)
            {     
               // Check for some consistency in the input fields and relations defintion. The related
               // object in the fields (input) data must represent the relation type.  So if we're
               // Company, and we have many Employee objects, we expect $key to be an numeric array here,
               // presumably of Employee object data.  If we just have one Employee object and not an
               // array, then our input/fields data does not match our ORM definition.  This would be
               // an irreconcilable mismatch and we would wind up with an invalid object state.
            
               // If the input is a numeric array, we consider that to be an array of objects, or it
               // could be an array of assoc arrays.  Regardless, this signifies to us that the input
               // field represents a 1 to many relationship.
               if (is_array($fieldValue) && !$this->ormIsAssoc($fieldValue)
                  && $relation['type'] != ORM_HAS_MANY
                  && $relation['type'] != ORM_MANY_TO_MANY)
               {
                  throw new InvalidStateException("{$relation['type']} setFields() detected an inconsistency in the input fields when compared to the defined related object definition. Field {$fieldKey} should be a numeric array of hashes/objects, indicating a many relationship.");
               }
               
               // If the input field value is an associtive array (not numeric), is the same as an object,
               // and also represents 1=1 relationship.
               if (is_array($fieldValue) && $this->ormIsAssoc($fieldValue)
                   && $relation['type'] != ORM_HAS_ONE
                   && $relation['type'] != ORM_MIGHT_HAVE_ONE
                   && $relation['type'] != ORM_BELONGS_TO)
               {
                  throw new InvalidStateException("setFields() detected an inconsistency in the input fields when compared to the defined related object definition. Field culprit: ". $fieldKey);               
               }
   
               // If the input field value is an oject, it means we're talking about a 1=1 relationship.
               if (is_object($fieldValue)
                   && $relation['type'] != ORM_HAS_ONE
                   && $relation['type'] != ORM_MIGHT_HAVE_ONE
                   && $relation['type'] != ORM_BELONGS_TO)
               {
                  throw new InvalidStateException("setFields() detected an inconsistency in the input fields when compared to the defined related object definition. Field culprit: ". $fieldKey);
               }
               
               // fieldValue will now represent another level of depth at this point.  It should be
               // one of the following:
               //  * An assoc array or object (has one, belongs to one relationsip)
               //  * An array of assoc arrays, or array of objects (has many relationship)               
               $this->ormSetRelationFields($fieldValue, $longClassName, $shortClassName, $relation['key']);                  
               break;
            }
         }         
      }      
   }

   
   
   // Return the value of an object variable, or null if not defined.
   // @param string $objField The field name/key/variable
   // @return mixed
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


   // Get the current object's mapped field data as an array of key=value pairs
   // @return array
   public function getFields()
   {
      $fields = array();

      foreach ($this->orm['objectFieldMap'] as $key => $value)
      {
         if (isset($this->orm['data'][$key]))
         {
            $fields[$key] = $this->orm['data'][$key];
         }
      }

   return $fields;
   }


   // Like getFields() except the field names returned are the database column names instead of the
   // object properties.
   // @return array
   public function getDbFields()
   {
      $fields = array();
      
      foreach ($this->orm['fieldMap'] as $dbField => $objField)
      {         
         if (isset($this->$objField))
         {
            $fields[$dbField] = $this->orm['data'][$objField];
         }
      }
      
      return $fields;
   }

   
   // Return an array of related objects in the dataset.  This returns only loaded objects if they
   // are related in the database, not any schema information or definitions. If no related objects
   // are present in memory, then you'll get an empty array.
   // @return array of ORM objects
   public function getRelations()
   {
      $relations = array();
      
      foreach ($this->orm['relations'] as $className => $someData)
      {
         $shortName = $this->getShortClassName($className);
         
         if (isset($this->{$shortName}))
         {
            $relations[$shortName] = $this->{$shortName};
         }
      }
      
      return $relations;
   }

   
   // Load data from the database and populate result into current instance of object.  Since an
   // object represents a single tuple, multiple rows (tuples) in the resultset of the query would
   // result in an error.
   //
   // @throws ORMException on error.
   // @param mixed $primaryKeyValue The primary key value representing the record to load
   // @return ORM Returns true when loaded.
   public function load($primaryKeyValue)
   {
      if (empty($this->orm['primaryKey']))
      {
         throw new ORMException("No primary key defined, therefore I cannot load anything.");
      }

      $primaryKeyField = $this->ormGetPrimaryKeyField();
      $conditions = $this->ormConditions(array("{$primaryKeyField} = {$primaryKeyValue}"));
      $ret = $this->ormLoad(ORM_THIS_OBJECT, 1, $conditions);

   return $ret;
   }



   // Load a tuple from the database, using a UNIQUE value for reference other than the primary key.
   //
   // loadBy("identifier", "uniquevalue") will work just like load()
   // Also magic method loadByN("value") applies.
   //
   // @throws ORMException on error.
   // @param string $objField The name of the field to use as a condition for loading
   // @param mixed $objValue The value of the field to use as a condition for loading
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
         throw new ORMException("'{$objField}' does not map to any database field in ORM class " . get_class($this));
      }

      $ret = $this->ormLoad(ORM_THIS_OBJECT, 1, $this->ormConditions($conditions));

   return $ret;
   }


   // Save (synchronize) data in object with database only if the ORM object has the dirty flag set,
   // indicating that data has changed internally. Automatically detect if we need to perform a SQL
   // INSERT or UPDATE operation.
   //
   // If the cascade flag is set, then the save operation will also be performed on related objects
   // along with this one.
   //
   // @throws InvalidStateException for any internal data state problems
   // @throws ORMException for all other errors
   public function save()
   {
      if ($this->orm['isDirty'] == false)
      {
         return;
      }
      
      if ($this->orm['dbSync'] == true)
      {
         $this->ormUpdate();
      }
      else
      {
         $this->ormSave();
      }
   }
   
   
   // Manually perform a cascading save operation regardless of the cascade save option defined on
   // the ORM object.
   // @throws InvalidStateException for any internal data state problems
   // @throws ORMException for all other errors
   public function cascadeSave()
   {
      $this->save();
      $this->ormCascadeSave();
   }

   
   // *** NOTE: This is just a fancy way to make a SQL UPDATE statement ***
   //
   // Perform a SQL UPDATE database operation for fields specified in $fieldsToUpdate and conditions
   // specified in the $conditions array.  Any field names in $fieldsToUpdate will be translated from
   // their object property into their equivalent database field name in the SQL statement.
   //
   // This allows you to perform a quick SQL UPDATE statement based off of the object field map.  No
   // before and after events will be triggered, and no object syncing of any kind will happen.  
   //
   // @throws ORMException on error
   // @param mixed $ormFields Array or object of key=>value pairs to update.  Theese are ORM object properties, not DB field names.
   // @param array $conditions Standard conditions array
   // @param string $limit LIMIT clause, e.g. "LIMIT 1"
   // @return mixed Returns number of rows updated, if any.
   public function quickUpdate($ormFields, $conditions, $limit=null)
   {
      if ((!is_array($ormFields) && !$this->isAssoc($ormFields)) && !is_object($ormFields))
      {
         throw new ORMException("ormFields is supposed to be either an associative array of key=value pairs, or an object with properties.");
      }      
      
      if (empty($ormFields))
      {
         throw new ORMException("ormFields cannot be empty.");
      }
      
      if (is_object($ormFields))
      {
         $ormFields = (array)$ormFields;
      }
      
      foreach ($this->orm['insertUpdateMap'] as $objVar => $dbVar)
      {
         if (isset($ormFields[$objVar]))
         {
            $dbFields[$dbVar] = $ormFields[$objVar];           
         }
      }
      
      // If dbFields is empty here, it means that no valid object fields were found in $ormFields.
      // We return 0 to signify that no rows were modified, since we won't be performing any SQL
      // UPDATE operation with no fields to update.
      if (empty($dbFields))
      {
         return 0;
      }

      $conditions = $this->ormConditions($conditions);      
      $this->orm['writer']->doUpdate($this->orm['tableNameDb'], $dbFields, $conditions, $limit);
      
      return $this->orm['writer']->countRows();
   }
   
   
   
   // Unsync the object and issue an SQL DELETE to remove it from the database table.  This will
   // remove all internal data from the internal data array, leaving an the object with no tuple
   // data, but otherwise intact with all settings and relations still defined.  Your calling code
   // will need to remove the object itself if desired.
   //
   // @throws InvalidStateException if there is no primary key
   // @throws ORMException on other errors
   public function delete()
   {
      $primaryKeyField = $this->ormGetPrimaryKeyField();
      $primaryKeyValue = $this->ormGetPrimaryKeyValue();

      if (empty($primaryKeyField))
      {
         throw new InvalidStateException("Delete failed: The ORM object did not have a primary key field properly defined");         
      }
      
      // If we have no id value, we can't really delete anything...
      if ($primaryKeyValue != 0 && empty($primaryKeyValue))
      {
         throw new InvalidStateException("Delete failed: The primary key was not set in this object");
      }
      
      $conditions = $this->ormConditions(array("{$primaryKeyField} = {$primaryKeyValue}"));
      $this->orm['writer']->doDelete($this->orm['tableNameDb'], $conditions, "LIMIT 1");
      
      $this->orm['data'] = array();
      $this->orm['isDirty'] = false;
      $this->orm['dbSync'] = false;
   }


   // Currently not implemented.
   public function cascadeDelete()
   {
      $this->delete();
      $this->ormCascadeDelete();
   }


   ////////////////////////////////////////////////////////////////////////////////////////////////////////////
   // Find methods.  These get data from db and return either an object or array of objects, or assoc array(s).
   ////////////////////////////////////////////////////////////////////////////////////////////////////////////



  /* Basic method to get tuple(s) that match specified criteria in conditions.
   *
   * Return an object, or if resultset from database has multiple rows/tuples, return an
   * array of objects.  Force return of resultset as an array by setting retArray to true,
   * even if result contains one row.
   *
   * If no results are found, return false.  If retArray is set to true, then empty array is
   * returned instead of false.
   *
   * Magic method findAll() will always return an array of objects, and is a cleaner way of
   * achieving the same result instead of setting retArray as true.
   *
   * On error return false no matter what.
   */

   public function find($conditions=array(), $order=NULL, $limit=NULL, $retArray=false)
   {
      $ret = $this->ormLoad(ORM_NEW_OBJECT, 0, $this->ormConditions($conditions), $order, $limit);

      if ($retArray == true)
      {
         if ($ret == false)
         {
            return array();
         }
      }

   return $ret;
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

   public function findBy($objField, $objValue, $conditions=array(), $order=NULL, $limit=NULL, $retArray=false)
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
         return false;
      }

      $ret = $this->ormLoad(ORM_NEW_OBJECT, 0, $this->ormConditions($conditions), $order, $limit);

      if ($retArray == true)
      {
         if ($ret == false)
         {
            return array();
         }
      }

   return $ret;
   }



  /* Get tuple by primary key.  This should always return a single row considering that the database is
   * correctly designed with a UNIQUE key as its primary key.
   *
   * If no record can be found, return false.
   */

   public function findByPrimaryKey($primaryKeyValue)
   {
      if (empty($this->orm['primaryKey']))
      {
         //error(E_WARNING, "No primary key defined, therefore I cannot find a tuple.");
         return false;
      }

      /* Determine primary key for table and generate conditions array to locate 1 row in db */
      $conditions = $this->ormConditions(array("{$this->orm['primaryKey']} = {$primaryKeyValue}"));

      $ret = $this->ormLoad(ORM_NEW_OBJECT, 1, $conditions);

   return $ret;
   }



   ////////////////////////////////////////////////////////////////////////////////////////////////////////////
   // Other various public methods.
   ////////////////////////////////////////////////////////////////////////////////////////////////////////////



  /* Load relations from database manually. If automatic nested relation loading is enabled, this is done for you.
   */

   public function loadRelations()
   {
      if (count($this->orm['relations']) > 0)
      {
         // We must have a primary key value set to load relations.
         $pkVal = $this->ormGetPrimaryKeyValue();

         if ($pkVal === null || $pkVal === false)
         {
            return false;
         }

         $this->load($pkVal);
      }

      return true;
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
         return false;
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

   public function countTotalRows()
   {
      if (empty($this->orm['primaryKey']))
      {
         //error(E_WARNING, "No primary key defined in my schema.  countTotalRows() requires this to be defined.");
         return false;
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
   // @return boolean true if dirty data exists, otherwise false
   public function isDirty()
   {
      return $this->orm['isDirty'];
   }


   // Determine if the ORM object has synched to the database.  Use this to determine if a load()
   // call is successful. Note that you must use isDirty() to detect local modifications to the
   // object data.  Once initially synched, then this will always return true.
   // @return boolean true if object successful synched to db, otherwise false.
   public function isSynched()
   {
      return $this->orm['dbSync'];
   }


   // Set the table db name that will be used in the SQL statements.  If for some reason you need to
   // reference a db table that doesn't match the name of the class, you call this method to tell ORM
   // to use the specified table instead.
   //
   // Example:  If you have a class called "Customer" but you need to reference the table like this:
   // "Customers", or even "mydatabase.Customer" 
   //
   // @param string $table Database table name.
   public function setTableDbName($dbTable)
   {
      $this->orm['tableNameDb'] = $dbTable;
      $this->reMap();
   }


   // Get the database table name.  This will usually match the class name unless otherwise set to
   // something else with the setTableDbName() method.
   // @return string The database table
   public function getTableDbName()
   {
      return $this->orm['tableNameDb'];
   }

   
   // Get the table name.  This will always match the class name, even if the actual database table
   // has been set to something else.  We need it to match the class name when converting SQL result
   // sets to class data.
   // @return string table name
   public function getTableName()
   {
      return $this->orm['tableName'];
   }
   
   
   // Magic method broker.  This makes the loadByN, findN, findByN, getN and setN methods work.
   // @param string $method The name of the method called.
   // @param array $args Arguments passed to the method.
   public function __call($method, $args)
   {
      // loadByX where X is a fieldObjName
      if (preg_match("/^loadBy(.+?)$/", $method, $matches))
      {
         /* First argument is value of field.  It is required */
         if (!isset($args[0]))
         {
            //error(E_WARNING, "Magic loadBy() method expects the field name.");
            return false;
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
            return false;
         }

         /* Default values to pass */
         if (!isset($args[1])) $args[1] = array();
         if (!isset($args[2])) $args[2] = NULL;
         if (!isset($args[3])) $args[3] = NULL;

         /* Call find() method with appropriate arguments */
         return $this->findBy($matches[1], $args[0], $args[1], $args[2], $args[3], true);
      }


      // findByX where X is a fieldObjName
      else if (preg_match("/^findBy(.+?)$/", $method, $matches))
      {
         /* First argument is value of field.  It is required */
         if (!isset($args[0]))
         {
            //error(E_WARNING, "Magic findBy() method expects the field value as the first argument");
            return false;
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

         return $this->find($args[0], $args[1], $args[2], true);
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
            return false;
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
   //
   // ORM protected and private methods for internal use.  Herein ends the public interface.
   //
   // NOTE: Some methods are public because they sometimes are called from other separate orm objects for the
   // purpose of building relationships/associations.
   //
   ////////////////////////////////////////////////////////////////////////////////////////////////////////////


   // Set the data field on the object.  Call any events, and set the dirty flag if the value changes.
   // @return boolean true if value set, false if value was not set
   public function ormSet($objField, $objValue)
   {
      // If the value is the same as the existing value, don't mark the ORM object as dirty since
      // nothing changed. No events are executed, and this will not prompt any SQL statements to
      // be executed, etc..            
      if (isset($this->orm['data'][$objField]) && $this->orm['data'][$objField] == $objValue)
      {
         return false;
      }
            
      if (method_exists($this, "onBeforeSet"))
      {
         $eventValue = $this->onBeforeSet($objField, $objValue);
         
         if ($eventValue != null)
         {
            $objValue = $eventValue;
         }
	
      }

      $this->orm['data'][$objField] = $objValue;
      $this->orm['isDirty'] = true;
	    
      if (method_exists($this, "onAfterSet"))
      {
         $this->onAfterSet($objField, $objValue);
      }
      
      return true;
   }
   
   
   // This method is part of setFields().  It is responsible for recursively setting field data on
   // related objects.  It can handle setting a single set of field data, or arrays of field data
   // for multiple related objects.  In theory, it should be able to keep traversing related objects
   // and their related objects.
   //
   // @throws ORMException for errors
   private function ormSetRelationFields($fields, $longClassName, $shortClassName, $relationKey)
   {
      list($primaryKeyField, $foreignKeyField) = preg_split("/:/", $relationKey);

      // If $this is synched to the DB, we should have a primary key.  The primary key/value will be
      // used to set data on related objects when $this is synched.
      if ($this->isSynched())
      {
         $primaryKeyValue = $this->ormGetPrimaryKeyValue();
         if (empty($primaryKeyField) || empty($foreignKeyField)) throw new ORMException("ormSetRelationFields(): Not passed in a properly formated relationKey: '{$relationKey}'");
         if (empty($primaryKeyValue)) throw new ORMException("ormSetRelationFields(): The primary object is synched, but has no primary key.");         
      }
      else
      {
         $primaryKeyValue = null;
      }
      
      // If fields is a numeric array, this signifies a 'has many' relationship.  We will presumably
      // have 1 or more data sets for the related object in the array.
      if (is_array($fields) && !$this->ormIsAssoc($fields))
      {
         $this->ormSetRelationFieldsHasMany($fields, $longClassName, $shortClassName, $foreignKeyField, $primaryKeyValue);
      }
      
      // If fields is not a numerically indexed array, we assume it's either an assoc array of
      // key=>value pairs, or an object with properties. This is a 'has one' relationship, so we
      // expect to find only a single object internally as well.
      else
      {
         $this->ormSetRelationFieldsHasOne($fields, $longClassName, $shortClassName, $foreignKeyField, $primaryKeyValue);
      }
   }
   
   
   // Part of setFields().  Sets fields on internal objects with has many relationships.
   // @throws ORMException for errors
   private function ormSetRelationFieldsHasMany($fields, $longClassName, $shortClassName, $foreignKeyField, $primaryKeyValue)
   {
      // If we do not have any objects loaded internally, then it means that our has many relationship
      // is defined but there is no data in the database.  The new data in fields will have to be
      // added as new objects to be inserted in the database later with save().
      if (!isset($this->orm['data'][$shortClassName]) || count($this->orm['data'][$shortClassName]) == 0)
      {
         $this->data[$shortClassName] = [];

         foreach ($fields as $relatedFields)
         {
            $relatedObj = new $longClassName;
            $relatedObj->setQueryBuilderReader($this->getReader());
            $relatedObj->setQueryBuilderWriter($this->getWriter());
            $relatedObj->setFields($relatedFields);
            if (!empty($primaryKeyValue)) $relatedObj->ormSet($foreignKeyField, $primaryKeyValue);
            $this->orm['data'][$shortClassName][] = $relatedObj;
         }
      }
         
      // We have 1 or more objects already loaded.  This is the most complex part.  We iterate
      // through the fields array, and grab the primary key value for each fieldset, then use that
      // id value to match up to the internal object.  We set the fields on the matched object.  If
      // we can't find a match, or the primary key value in the fields data isn't present, then we
      // just create a new object in both cases and add it to the array to be saved in the db later
      // with save()
      else if (count($this->orm['data'][$shortClassName]) > 0)
      {
         if (!isset($this->orm['data'][$shortClassName][0]) || !method_exists($this->orm['data'][$shortClassName][0], "ormGetPrimaryKeyField"))
         {
            throw new ORMException("ormSetRelationFieldsHasMany() detected a numeric internal array for {$shortClassName}, but index 0 was not an ORM object.");
         }
            
         $relatedPrimaryKeyField = $this->orm['data'][$shortClassName][0]->ormGetPrimaryKeyField();
            
         if (empty($relatedPrimaryKeyField))
         {
            throw new ORMException("ormSetRelationFieldsHasMany() tried to get primary key field for related object {$relatedPrimaryKeyField} but it returned an empty value. This could mean the related object is improperly configured.");               
         }
            
         foreach ($fields as $relatedFields)
         {
            // If we have a primary key, we use that to find the matching related object in memory.
            if (isset($relatedFields[$relatedPrimaryKeyField]) || !empty($relatedFields[$relatedPrimaryKeyField]))
            {
               $relatedPrimaryKeyValue = $relatedFields[$relatedPrimaryKeyField];
               $relatedObj = $this->ormFindRelationByPrimaryKey($shortClassName, $relatedPrimaryKeyValue);
               
               // If we found a related object, then we update the fields on it.
               if ($relatedObj)
               {
                  $relatedObj->setFields($relatedFields);
               }
                  
               // If we get here, and haven't found a matching object in memory, we create a new
               // object here and add it to the array.
               else
               {
                  $relatedObj = new $longClassName;
                  $relatedObj->setQueryBuilderReader($this->getReader());
                  $relatedObj->setQueryBuilderWriter($this->getWriter());
                  $relatedObj->setFields($relatedFields);
                  if (!empty($primaryKeyValue)) $relatedObj->ormSet($foreignKeyField, $primaryKeyValue);
                  $this->orm['data'][$shortClassName][] = $relatedObj;                     
               }
            }
               
            // If we have no primary key in relatedFields, assume this is a new object and create
            // it to be inserted into the db later with save()
            else
            {
               $relatedObj = new $longClassName;
               $relatedObj->setQueryBuilderReader($this->getReader());
               $relatedObj->setQueryBuilderWriter($this->getWriter());
               $relatedObj->setFields($relatedFields);
               if (!empty($primaryKeyValue)) $relatedObj->ormSet($foreignKeyField, $primaryKeyValue);
               $this->orm['data'][$shortClassName][] = $relatedObj;
            }
         }
      }      
   }
   
   
   // Part of setFields().  This sets field data on internal objects that have a has one relationship.
   private function ormSetRelationFieldsHasOne($fields, $longClassName, $shortClassName, $foreignKeyField, $primaryKeyValue)
   {
      // If it exists, then we update the fields on it.
      if (isset($this->orm['data'][$shortClassName]))
      {
         $this->orm['data'][$shortClassName]->setFields($fields);   
      }
         
      // If it does not exist, then we will create a new object to be inserted in the db later with save()
      else
      {
         $relatedObj = new $longClassName;
         $relatedObj->setQueryBuilderReader($this->getReader());
         $relatedObj->setQueryBuilderWriter($this->getWriter());
         $relatedObj->setFields($fields);
         if (!empty($primaryKeyValue)) $relatedObj->ormSet($foreignKeyField, $primaryKeyValue);
         $this->orm['data'][$shortClassName] = $relatedObj;
      }      
   }
   
   
   // This method attempts to find a related object by its primary key value, assuming the ORM object
   // has been synched with the database via load or find.
   // @param string $shortClassName The short class name of the related object
   // @param mixed $primaryKeyValue The value of the primary key to match
   // @return mixed Returns the related object, or false on no match.
   protected function ormFindRelationByPrimaryKey($shortClassName, $primaryKeyValue)
   {
      foreach ($this->orm['data'][$shortClassName] as $relatedObject)
      {
         if (method_exists($relatedObject, "ormGetPrimaryKeyValue") && $relatedObject->ormGetPrimaryKeyValue() == $primaryKeyValue)
         {
            return $relatedObject;
         }
      }
      
      return false;
   }
   
   
   // Return true if array is an associtive array, or false if a numeric array.  It does not check for
   // sequential numbering of sequential arrays.
   // http://stackoverflow.com/questions/173400/how-to-check-if-php-array-is-associative-or-sequential
   // @param array $arr
   // @return boolean
   protected function ormIsAssoc($arr)
   {
      foreach ($arr as $key => $value)
      {
         if (is_string($key))
         {
            return true;
         }
      }
      
      return false;
   }
   
   
   // Add a mapping between an object field and a database field.  This is designed to be called
   // from your ORM object's constructor.
   // @param string $objectField The name of your object's variable
   // @param string $dbField The database field name equivalent. 
   protected function addMap($objectField, $dbField)
   {
      $key = "{$this->orm['tableNameDb']}.{$dbField}";
      $this->orm['selectMap'][$key] = $this->orm['tableName'] . "_" .$objectField;
      $this->orm['fieldMap'][$dbField] = $objectField;
      $this->orm['objectFieldMap'][$objectField] = $this->orm['tableNameDb'] . "." . $dbField;
      $this->orm['insertUpdateMap'][$objectField] = $dbField;

      // Set the class property with a null value.  Also need to unset the dirty flag because of the
      // way we're doing object setting.
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
   // @param int $relation_type One of the defined ORM relation definitions.
   // @param string $class_name The name of the related ORM class.
   // @param string $keyMap Local key and foreign key relatonship "myId:foreignId"
   // @param string $linkTable If using a db link table for many to many relationships, specify it here
   // @return boolean true/false
   protected function addRelation($relation_type, $class_name, $keyMap, $linkTable=null)
   {
      $short_class_name = $this->getShortClassName($class_name);
      
      if ($this->orm['loadRelations'] == false) return true;
      $r = array();

      switch ($relation_type)
      {
         case ORM_HAS_ONE:
           $r['type'] = $relation_type;
           $r['key'] = $keyMap; // foreign key
           $this->orm['relations'][$class_name] = $r;
           $this->orm['classes'][$class_name] = new $class_name(false);
           $this->{$short_class_name} = $this->orm['classes'][$class_name];
         break;

         case ORM_MIGHT_HAVE_ONE:
           $r['type'] = $relation_type;
           $r['key'] = $keyMap; // foreign key
           $this->orm['relations'][$class_name] = $r;
           $this->orm['classes'][$class_name] = new $class_name(false);
           $this->{$short_class_name} = $this->orm['classes'][$class_name];
         break;
         
         case ORM_HAS_MANY:
           $relationMap[$class_name] = true;
           $r['type'] = $relation_type;
           $r['key'] = $keyMap; // foreign key
           $this->orm['relations'][$class_name] = $r;
           $this->orm['classes'][$class_name] = new $class_name(false);
           $this->{$short_class_name} = array();
         break;

         case ORM_MANY_TO_MANY:
           $relationMap[$class_name] = true;
           $r['type'] = $relation_type;
           $r['key'] = $keyMap; // foreign key
           $r['linkTable'] = $linkTable;
           $this->orm['relations'][$class_name] = $r;
           $this->orm['classes'][$class_name] = new $class_name(false);
           $this->{$short_class_name} = array();
         break;

         case ORM_BELONGS_TO:
           $relationMap[$class_name] = true;
           $r['type'] = $relation_type;
           $r['key'] = $keyMap; // foreign key
           $this->orm['relations'][$class_name] = $r;
           $this->orm['classes'][$class_name] = new $class_name(false);
           $this->{$short_class_name} = $this->orm['classes'][$class_name];
         break;
                  
         default:
            return false;
         break;
      }

   return true;
   }

   
  /* Add a Unique field restraint to field(s).  This will cause ORM to use the doUniqueInsert() method
   * when making calls to insert data to the database.
   *
   * At some point, this could also perform checks before ever talking to the db.
   */

   protected function addUnique($fields)
   {
      $this->orm['unique'] = true;
   }


   // Return the current primary key value in the object.  This is a public function because the ORM
   // code has so reference extrnal objects to manage relationships, and if the method was protected,
   // this would throw errors.
   public function ormGetPrimaryKeyValue()
   {
      $pkField = $this->orm['primaryKey'];

      if (isset($this->$pkField))
      {
         return $this->$pkField;
      }
      else
      {
         return NULL;
      }
   }


   // Return the primary key field name as defined in the object. Needs to be a public function so
   // that other orm objects can call this method when needed.
   // @return (string) field name
   public function ormGetPrimaryKeyField()
   {
      return $this->orm['primaryKey'];
   }


   // Return the primary key field name as defined in the database.  Needs to be a public function so
   // that other orm objects can call this method when needed.
   // @return (string) field name
   public function ormGetPrimaryKeyDbField()
   {
      $primaryKey = $this->orm['primaryKey'];
      list($table, $dbKey) = explode(".", $this->orm['objectFieldMap'][$primaryKey]);
      return $dbKey;
   }


   // Check if the current ORM object has any relations defined.
   // @return boolean true if relations are defined, false otherwise.
   private function ormHasRelations()
   {
      if (count($this->orm['relations']) > 0)
      {
         return true;
      }
      else
      {
         return false;
      }
   }

   
   // Perform the save operation on all related objects.  They will determine their internal state and
   // determine if they need to perform SQL INSERT or UPDATE commands to the database.
   //
   // Note: What happens if rollbackTransaction fails?  Right now this could leave the DB connection in
   // an invalid state.  I'm not sure what to check for in this edge case.
   //
   // @throws InvalidStateException for data integrity problems
   // @throws ORMException on all other errors
   private function ormCascadeSave()
   {
      $primaryKeyValue = $this->ormGetPrimaryKeyValue();
      
      if ($primaryKeyValue != 0 && empty($primaryKeyValue))
      {
         throw new InvalidStateException("Cascade save failed to start: The primary key was not set in this object.");
      }
      
      try
      {
         if ($this->orm['enableTransactions']) $this->orm['writer']->beginTransaction();         
      }
      catch (\Exception $e)
      {
            throw new ORMException("Cascade save failed to start: Could not start a transaction.", 0, $e);         
      }
      
      foreach ($this->orm['relations'] as $longClassName => $relDetail)
      {
         $shortClassName = $this->getShortClassName($longClassName);

         if (!isset($this->orm['data'][$shortClassName]) || empty($this->orm['data'][$shortClassName]))
         {
            continue;
         }

         list($primaryKeyField, $foreignKeyField) = explode(":", $relDetail['key']);

         if (empty($primaryKeyField) || empty($foreignKeyField))
         {
            $this->cascadeSaveInvalidStateException("There is an invalid key 'local:foreign' in relation definition for {$shortClassName}");
         }
	 
         // If we have a numeric array, we have a 'has many' relationship, and we need to iterate
         // through the array of related objects.  If the array is empty, then we just won't do
         // anything and move on...
         if (is_array($this->orm['data'][$shortClassName]) && !$this->isAssoc($this->orm['data'][$shortClassName]))
         {
            $i = 0;
            
            foreach ($this->orm['data'][$shortClassName] as $objRef)
            {
               if (!method_exists($objRef, "get") || !method_exists($objRef, "save"))
               {
                  $this->cascadeSaveInvalidStateException("Related object {$shortClassName} in array position {$i} is not a valid ORM object.");                  
               }
               
               // Check that the foreign key equals our primary key.  If not, we assume this related
               // object has not been saved yet.  Set the foreign key and save it, which will issue
               // an INSERT statement, creating the record.
               //
               // NOTE: If there is the wrong foreign key value in the object, I have made the decision
               // to overwrite it.  This would break the related record's relationship, but why would
               // it be part of our object anyway?  The user will be at fault for passing the wrong
               // related object manually or via setFields().
               if ($objRef->get($foreignKeyField) != $primaryKeyValue)
               {
                  $objRef->set($foreignKeyField, $primaryKeyValue);
               }

               try
               {
                  $objRef->save();                     
               }
               catch (\Exception $e)
               {
                  $this->cascadeSaveORMException("Caught exception when saving related object {$shortClassName} in array position {$i}", $e);                     
               }
               
               $i++;
            }
         }
         
         // Here we assume an assoc. array of key=>value pairs, or an object with properties.  This
         // signifies a 'has one' relationship, so we attempt to save this single related object.
         else
         {
            $objRef = $this->orm['data'][$shortClassName];
            
            if (!method_exists($objRef, "get") || !method_exists($objRef, "save"))
            {
               $this->cascadeSaveORMException("Related object {$shortClassName} is not a valid ORM object");                     
            }
               
            // Do the same check as before for foreign key...
            // Note: This will also ensure that any object with a 1=1 (has one, belongs to, etc...)
            // relationship always has a new record created when doing cascade save...
            if ($objRef->get($foreignKeyField) != $primaryKeyValue)
            {
               $objRef->set($foreignKeyField, $primaryKeyValue);
            }

            try
            {
               $objRef->save();                     
            }
            catch (\Exception $e)
            {
               $this->cascadeSaveORMException("Caught exception when saving related object {$shortClassName}", $e);
            }
         }
      }
      
      if ($this->orm['enableTransactions']) $this->orm['writer']->commitTransaction();
   }

   
   // Utility function to rollback transaction if enabled, and throw an exception
   private function cascadeSaveInvalidStateException($message, \Exception $e = null)
   {
      if ($this->orm['enableTransactions'])
      {
         $this->orm['writer']->rollbackTransaction();
         throw new InvalidStateException("Cascade save transaction rollback: {$message}", 0, $e);                  
      }
      else
      {
         throw new InvalidStateException("Cascade save failure: {$message}: No transaction rollback attempted.", 0, $e);
      }      
   }


   // Utility function to rollback transaction if enabled, and throw an exception
   private function cascadeSaveORMException($message, \Exception $e = null)
   {
      if ($this->orm['enableTransactions'])
      {
         $this->orm['writer']->rollbackTransaction();
         throw new ORMException("Cascade save transaction rollback: {$message}", 0, $e);
      }
      else
      {
         throw new ORMException("Cascade save failure: {$message}: No transaction rollback attempted.", 0, $e);
      }      
   }
   
   
   // Perform a SQL INSERT operation on the object.
   //
   // TODO: Implement better handing of primary keys.  Right now we only support auto increment
   //       keys in MySQL.  Lame.
   //
   // @throws ORMException on error
   public function ormSave()
   {
      if (method_exists($this, "onBeforeSave"))
      {
         $this->onBeforeSave();
      }
      
      if (empty($this->orm['primaryKey']))
      {
         throw new ORMException("No primary key defined, therefore I cannot save a tuple in the database.");
      }

      foreach ($this->orm['insertUpdateMap'] as $objVar => $dbVar)
      {
         $fields[$dbVar] = $this->orm['data'][$objVar];
      }

      if ($this->orm['unique'] == true)
      {
         $iMethod = "doUniqueInsert";
      }
      else
      {
         $iMethod = "doInsert";
      }

      if (!$this->orm['writer']->{$iMethod}($this->orm['tableNameDb'], $fields))
      {
         throw new ORMException("The QueryBuilder could not perform the operation: {$iMethod}");
      }

      // Right now this assumes auto_incrementing primary keys in MySQL only.  If the primary key is
      // empty, attempt to get it from the db.
      
      $primaryKeyField = $this->ormGetPrimaryKeyField();
      $primaryKeyValue = $this->ormGetPrimaryKeyValue();
      
      if (empty($primaryKeyValue))
      {
         $this->orm['data'][$primaryKeyField] = $this->orm['writer']->lastInsertId();
      }
      
      $this->orm['dbSync'] = true;
      $this->orm['isDirty'] = false;
      
      if (method_exists($this, "onAfterSave"))
      {
         $this->onAfterSave();
      }

      return true;
   }
   
   
   private function ormCascadeDelete()
   {
      $primaryKeyValue = $this->ormGetPrimaryKeyValue();
      
      if ($primaryKeyValue != 0 && empty($primaryKeyValue))
      {
         throw new InvalidStateException("Cascade delete failed to start: The primary key was not set in this object.");
      }
      
      try
      {
         if ($this->orm['enableTransactions']) $this->orm['writer']->beginTransaction();         
      }
      catch (\Exception $e)
      {
            throw new ORMException("Cascade save failed to start: Could not start a transaction.", 0, $e);         
      }
      
      foreach ($this->orm['relations'] as $longClassName => $relDetail)
      {
         $shortClassName = $this->getShortClassName($longClassName);

         if (!isset($this->orm['data'][$shortClassName]) || empty($this->orm['data'][$shortClassName]))
         {
            continue;
         }

         list($primaryKeyField, $foreignKeyField) = explode(":", $relDetail['key']);

         if (empty($primaryKeyField) || empty($foreignKeyField))
         {
            $this->cascadeDeleteInvalidStateException("There is an invalid key 'local:foreign' in relation definition for {$shortClassName}");
         }
	 
         // If we have a numeric array, we have a 'has many' relationship, and we need to iterate
         // through the array of related objects.  If the array is empty, then we just won't do
         // anything and move on...
         if (is_array($this->orm['data'][$shortClassName]) && !$this->isAssoc($this->orm['data'][$shortClassName]))
         {
            $i = 0;
            
            foreach ($this->orm['data'][$shortClassName] as $objRef)
            {
               if (!method_exists($objRef, "get") || !method_exists($objRef, "delete"))
               {
                  $this->cascadeDeleteInvalidStateException("Related object {$shortClassName} in array position {$i} is not a valid ORM object.");                  
               }
               
               // Only delete related objects that have an actual primary key value set.  Ignore the rest.
               if ($objRef->get($foreignKeyField) == $primaryKeyValue)
               {
                  try
                  {
                     $objRef->delete();                     
                  }
                  catch (\Exception $e)
                  {
                     $this->cascadeDeleteORMException("Caught exception when deleting related object {$shortClassName} in array position {$i}", $e);                     
                  }
               }
               
               $i++;
            }
         }
         
         // Here we assume an assoc. array of key=>value pairs, or an object with properties.  This
         // signifies a 'has one' relationship, so we attempt to save this single related object.
         else
         {
            $objRef = $this->orm['data'][$shortClassName];
            
            if (!method_exists($objRef, "get") || !method_exists($objRef, "delete"))
            {
               $this->cascadeDeleteInvalidStateException("Related object {$shortClassName} is not a valid ORM object");                     
            }
               
            // Only delete related objects that have an actual primary key value set.  Ignore the rest.
            if ($objRef->get($foreignKeyField) == $primaryKeyValue)
            {
               try
               {
                  $objRef->delete();                     
               }
               catch (\Exception $e)
               {
                  $this->cascadeDeleteORMException("Caught exception when deleting related object {$shortClassName}", $e);                     
               }
            }
         }
      }
      
      if ($this->orm['enableTransactions']) $this->orm['writer']->commitTransaction();
   }
   
   
   // Utility function to rollback transaction if enabled, and throw an exception
   private function cascadeDeleteInvalidStateException($message, \Exception $e = null)
   {
      if ($this->orm['enableTransactions'])
      {
         $this->orm['writer']->rollbackTransaction();
         throw new InvalidStateException("Cascade delete transaction rollback: {$message}", 0, $e);                  
      }
      else
      {
         throw new InvalidStateException("Cascade delete failure: {$message}: No transaction rollback attempted.", 0, $e);
      }      
   }


   // Utility function to rollback transaction if enabled, and throw an exception
   private function cascadeDeleteORMException($message, \Exception $e = null)
   {
      if ($this->orm['enableTransactions'])
      {
         $this->orm['writer']->rollbackTransaction();
         throw new ORMException("Cascade delete transaction rollback: {$message}", 0, $e);
      }
      else
      {
         throw new ORMException("Cascade delete failure: {$message}: No transaction rollback attempted.", 0, $e);
      }      
   }
   
   
   // Perform a SQL update operation on this object.
   // @throws InvalidStateException
   // @throws ORMException
   public function ormUpdate()
   {
      if (method_exists($this, "onBeforeUpdate"))
      {
         $this->onBeforeUpdate();
      }
      
      $primaryKeyField = $this->ormGetPrimaryKeyField();
      $primaryKeyValue = $this->ormGetPrimaryKeyValue();

      if ($primaryKeyValue !=0 && empty($primaryKeyValue))
      {
         throw new InvalidStateException("The object did not have a primary key set.");                  
      }

      foreach ($this->orm['insertUpdateMap'] as $objVar => $dbVar)
      {        
         $fields[$dbVar] = $this->orm['data'][$objVar];
      }

      $conditions = $this->ormConditions(array("{$primaryKeyField} = {$primaryKeyValue}"));      
      $this->orm['writer']->doUpdate($this->orm['tableNameDb'], $fields, $conditions, "LIMIT 1");

      if (method_exists($this, "onAfterUpdate"))
      {
         $this->onAfterUpdate();
      }

   return true;
   }
      
   
   // Start the data loading process.  Build and execute SQL query / queries based on the object maps
   // and relationships defined in the ORM object. This is pretty complex stuff.
   //
   // @return (array)  Returns an array of ORM objects constructed from the SQL resultset. 
   private function ormLoad($type=ORM_THIS_OBJECT, $expectedRows=1, $conditions, $order=NULL, $limit=NULL)
   {
      $hasMany = 0;
      
      // Call the beforeload callback in the current instance.  Make sure your callback defines the
      // function arguments to be passed BY REFERENCE so you can modify them as needed!
      if (method_exists($this, "onBeforeLoad"))
      {
         // If we detect a false return value, stop the load process.
         if ($this->onBeforeLoad($type, $conditions, $order, $limit) === false)
         {
            return array();
         }
      }
      
      // Define the primary query now to get the minimal from the database.  Subsequent call to
      // parimaryQuery() will define any JOINS for that query.  Subsequent Has many joins will go
      // into subsequent queries.  See code/comments below.
      $this->primaryQuery(array($this->orm['tableNameDb']), $this->orm['selectMap'], $conditions, $order, $limit);


      // Iterate through any relations to define any JOINS and additional queries...
      foreach ($this->orm['relations'] as $class => $relDetail)
      {
         // We add to the primary query by making JOINS for every relationship of this type.
         if ($relDetail['type'] == ORM_HAS_ONE || $relDetail['type'] == ORM_BELONGS_TO || $relDetail['type'] == ORM_MIGHT_HAVE_ONE)
         {
            $classRef = $this->orm['classes'][$class];
            $foreignTable = $classRef->orm['tableNameDb'];
            $expectedRows = 0;

            // Might have one signifies that there is a possible 1 to 1 mapping, but it is possible
            // that the row in the foreign table does not exist.
            if ($relDetail['type'] == ORM_MIGHT_HAVE_ONE)
            {
               $joinType = "LEFT JOIN";
            }
            else
            {
               $joinType = "INNER JOIN";
            }

            list($localKey, $foreignKey) = explode(":", $relDetail['key']);

            // Get local table and key from map
            $fieldArrLocal = explode(".", $this->orm['objectFieldMap'][$localKey]);
            $localKeyDb = array_pop($fieldArrLocal);
            $localTable = implode(".", $fieldArrLocal); 

            // Get foreign table and key from map
            $fieldArrForeign = explode(".", $classRef->orm['objectFieldMap'][$foreignKey]);
            $foreignKeyDb = array_pop($fieldArrForeign);
            $foreignTable = implode(".", $fieldArrForeign); 

            $tables[$foreignTable] = "LEFT JOIN {$localTable}.{$localKeyDb} = {$foreignTable}.{$foreignKeyDb}";               

            // add to primary query queue
            $fields = array_merge($this->orm['selectMap'], $classRef->orm['selectMap']);            
            $this->primaryQuery($tables, $fields);
         }

         // MANY relationships are a little bit more complex.  See below.
         else if ($relDetail['type'] == ORM_HAS_MANY)
         {
            $classRef = $this->orm['classes'][$class];
            $foreignTable = $classRef->orm['tableNameDb'];
            $expectedRows = 0; // We probably will have multiple rows with this relation

            list($localKey, $foreignKey) = explode(":", $relDetail['key']);

            // Get local table and key from map
            $fieldArrLocal = explode(".", $this->orm['objectFieldMap'][$localKey]);
            $localKeyDb = array_pop($fieldArrLocal);
            $localTable = implode(".", $fieldArrLocal); 

            // Get foreign table and key from map
            $fieldArrForeign = explode(".", $classRef->orm['objectFieldMap'][$foreignKey]);
            $foreignKeyDb = array_pop($fieldArrForeign);
            $foreignTable = implode(".", $fieldArrForeign); 

            $tables[$foreignTable] = "LEFT JOIN {$localTable}.{$localKeyDb} = {$foreignTable}.{$foreignKeyDb}";               

            $fields = array_merge($this->orm['selectMap'], $classRef->orm['selectMap']);
            
            // We can only have a single MANY (SQL LEFT JOIN) in our primary query.  If we have
            // additional MANY relationships, we need to make separate queries for each of those.
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
         
         // This relationship requires a link table.  The local key and the foreign key must both be
         // present in the link table.  We get map the db fields from each respective model.
         else if ($relDetail['type'] == ORM_MANY_TO_MANY)
         {            
            $classRef = $this->orm['classes'][$class];
            $linkTable = $relDetail['linkTable']; 
            $expectedRows = 0; // We probably will have multiple rows with this relation
            
            list($localKey, $foreignKey) = explode(":", $relDetail['key']);

            // Get local table and key from map
            $fieldArrLocal = explode(".", $this->orm['objectFieldMap'][$localKey]);
            $localKeyDb = array_pop($fieldArrLocal);
            $localTable = implode(".", $fieldArrLocal); 

            // Get foreign table and key from map
            $fieldArrForeign = explode(".", $classRef->orm['objectFieldMap'][$foreignKey]);
            $foreignKeyDb = array_pop($fieldArrForeign);
            $foreignTable = implode(".", $fieldArrForeign); 

            // First JOIN the link table and get rows from there where our primary key = foreign key
            // of the same name.  This is one side of the many to many relationship.
            $tables[$linkTable] = "LEFT JOIN {$localTable}.{$localKeyDb} = {$linkTable}.{$localKeyDb}";

            // Secondly JOIN the foreign table, or the one that has a ORM model and the one that we want
            // to save in our model, based on the other key in the link table, which is the primary key
            // of the foreign table/object.
            $tables[$foreignTable] = "LEFT JOIN {$linkTable}.{$foreignKeyDb} = {$foreignTable}.{$foreignKeyDb}";

            $fields = array_merge($this->orm['selectMap'], $classRef->orm['selectMap']);

            // We can only have a single MANY, (SQL LEFT JOIN) in our primary query.  The only
            // exception is this one, where a many to many needs two left joins.  If we have additional
            // MANY relationships, we need to make separate queries for each of those.
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

      // We could return true/false for a load() method, or data for a find() method...      
      return $ret;
   }



   // This is part of ormLoad()
   // Convert tuples to objects.  Once the queries are built and executed with ormLoad(), execution
   // is handed off to us to actually convert the SQL flat resultset into a series of objects.
   // @return array Array of objects created for resultset, or true if loading locally.
   private function ormConvertTuplesToObjects($type=ORM_THIS_OBJECT)
   {
      $objects = array();
      $index = array();
      
      // Hold any objects that need to load nested relations.  We'll do this after we get all the
      // results for load or find operation.  We have to wait because if we execute another query
      // before we fetch all the data from the previous query, the mysql client will free up the
      // rest of the resulset.  Other DB engines could exhibit similar behavior...
      $this->orm['objectsWaitingForRelations'] = array();
      
      // Iterate through the query queue, executing each db query and converting each resulset...
      foreach ($this->orm['queryQueue'] as $queue)
      {
         if (!$this->orm['reader']->doSelect($queue['tables'], $queue['fields'], $queue['conditions'], $queue['order'], $queue['limit']))
         {
            return false;
         }
	 
         $numRows = $this->orm['reader']->numRows();
	 
         if ($numRows == 0)
         {
            return false;
         }
	 
         // Set data locally for the load() methods in current object.
         if ($type == ORM_THIS_OBJECT)
         {
            while ($row = $this->orm['reader']->fetchArray())
            {
               $this->ormConvertTupleThis($type, $row);   
            }
   
            // Just return true
            $objects = true;
         }
         
         // Make new object(s) and return them for the various find() methods.  Store each object in
         // an index array so that we can go back and populate any relation data into the object as
         // we iterate through the different resultsets.
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

      // Load any related data on any objects now that we've converted our resulset from the DB.
      foreach ($this->orm['objectsWaitingForRelations'] as $objRef)
      {
         $objRef->loadRelations();  
      }

      $this->orm['objectsWaitingForRelations'] = null;
      return $objects;
   }


   // This is part of ormLoad()
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
         if (strtolower($resClass) == $shortClassName) $this->set($resParam, $value);
      }

      $this->orm['dbSync'] = true;
      $this->orm['isDirty'] = false;
      
      // Set values in related objects in $this
      foreach ($this->orm['relations'] as $class => $detail)
      {
         $shortClass = $this->getShortClassName($class);

         if ($detail['type'] == ORM_HAS_ONE || $detail['type'] == ORM_BELONGS_TO || $detail['type'] == ORM_MIGHT_HAVE_ONE)
         {
            $objRef = $this->{$shortClass};
            $objRef->setQueryBuilderReader($this->getReader());
            $objRef->setQueryBuilderWriter($this->getWriter());
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === false) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);
            $this->setForeignKeyValue($detail, $objRef);
            if ($this->orm['loadRelations'])
            {
               $objRef->enableNestedRelations();
               $this->orm['objectsWaitingForRelations'][] = $objRef;
            }
            else
            {
               $objRef->disableNestedRelations();
            }
            $objRef->orm['dbSync'] = true;
            $objRef->orm['isDirty'] = false;
            if (method_exists($objRef, "onAfterLoad")) $objRef->onAfterLoad($type);
            unset($objRef);
         }

         if ($detail['type'] == ORM_HAS_MANY)
         {
            $objRef = new $class;
            $objRef->setQueryBuilderReader($this->getReader());
            $objRef->setQueryBuilderWriter($this->getWriter());
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === false) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);
            if ($numFields > 0) $this->orm['data'][$shortClass][] = $objRef;            
            $this->setForeignKeyValue($detail, $objRef);
            if ($this->orm['loadRelations'])
            {
               $objRef->enableNestedRelations();
               $this->orm['objectsWaitingForRelations'][] = $objRef;
            }
            else
            {
               $objRef->disableNestedRelations();
            }
            $objRef->orm['dbSync'] = true;
            $objRef->orm['isDirty'] = false;
            if (method_exists($objRef, "onAfterLoad")) $objRef->onAfterLoad($type);
            unset($objRef);
         }

         if ($detail['type'] == ORM_MANY_TO_MANY)
         {
            $objRef = new $class;
            $objRef->setQueryBuilderReader($this->getReader());
            $objRef->setQueryBuilderWriter($this->getWriter());
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === false) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);
            if ($numFields > 0) $this->orm['data'][$shortClass][] = $objRef;            
            $this->setForeignKeyValue($detail, $objRef);
            if ($this->orm['loadRelations'])
            {
               $objRef->enableNestedRelations();
               $this->orm['objectsWaitingForRelations'][] = $objRef;
            }
            else
            {
               $objRef->disableNestedRelations();
            }
            $objRef->orm['dbSync'] = true;
            $objRef->orm['isDirty'] = false;
            if (method_exists($objRef, "onAfterLoad")) $objRef->onAfterLoad($type);
            unset($objRef);
         }
      }

   return $this;
   }


   // This is part of ormLoad()
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

      if (method_exists($newObj, "onBeforeLoad")) { if ($newObj->onBeforeLoad($type) === false) continue; }

      // Create the new object and populate the object variables with data.  We only do this once.
      if ($newObj == null)
      {
         $newObj = new $className;
         $newObj->setQueryBuilderReader($this->getReader());
         $newObj->setQueryBuilderWriter($this->getWriter());
         $newObj->setTableDbName($this->getTableDbName());
         $newObj->orm['dbSync'] = true;
         $newObj->orm['isDirty'] = false;
         
         // Set values in newObj
         foreach ($resultset as $key => $value)
         {
            list($resClass, $resParam) = explode("_", $key, 2);
            if (strtolower($resClass) == strtolower($this->getShortClassName(get_class($newObj)))) $newObj->set($resParam, $value);
         }         
      }
      
      // Set values in related objects in newObj
      foreach ($newObj->orm['relations'] as $class => $detail)
      {
         $shortClass = $this->getShortClassName($class);
         
         if ($detail['type'] == ORM_HAS_ONE || $detail['type'] == ORM_BELONGS_TO || $detail['type'] == ORM_MIGHT_HAVE_ONE)
         {
            $objRef = new $class;
            $objRef->setQueryBuilderReader($this->getReader());
            $objRef->setQueryBuilderWriter($this->getWriter());
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === false) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);
            $newObj->setForeignKeyValue($detail, $objRef);
            if ($this->orm['loadRelations'])
            {
               $objRef->enableNestedRelations();
               $this->orm['objectsWaitingForRelations'][] = $objRef;
            }
            else
            {
               $objRef->disableNestedRelations();
            }
            $objRef->orm['dbSync'] = true;
            $objRef->orm['isDirty'] = false;
            if ($numFields > 0) $newObj->{$shortClass} = $objRef;
            if (method_exists($objRef, "onAfterLoad")) $objRef->onAfterLoad($type);
         }

         if ($detail['type'] == ORM_HAS_MANY)
         {
            $objRef = new $class;
            $objRef->setQueryBuilderReader($this->getReader());
            $objRef->setQueryBuilderWriter($this->getWriter());
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === false) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);
            if ($numFields > 0)
            {
               // We need to get a reference, modify it, then re-set it.
               $ref = $newObj->{$shortClass};
               $ref[] = $objRef;
               $newObj->{$shortClass} = $ref;
            }
            $newObj->setForeignKeyValue($detail, $objRef);
            if ($this->orm['loadRelations'])
            {
               $objRef->enableNestedRelations();
               $this->orm['objectsWaitingForRelations'][] = $objRef;
            }
            else
            {
               $objRef->disableNestedRelations();
            }
            $objRef->orm['dbSync'] = true;
            $objRef->orm['isDirty'] = false;
            if (method_exists($objRef, "onAfterLoad")) $objRef->onAfterLoad($type);
            unset($objRef);
         }

         if ($detail['type'] == ORM_MANY_TO_MANY)
         {
            $objRef = new $class;
            $objRef->setQueryBuilderReader($this->getReader());
            $objRef->setQueryBuilderWriter($this->getWriter());
            if (method_exists($objRef, "onBeforeLoad")) { if ($objRef->onBeforeLoad($type) === false) continue; }
            $numFields = $this->ormPopulateFields($resultset, $shortClass, $objRef);            
            if ($numFields > 0)
            {
               // We need to get a reference, modify it, then re-set it.
               $ref = $newObj->{$shortClass};
               $ref[] = $objRef;
               $newObj->{$shortClass} = $ref;
            }
            $newObj->setForeignKeyValue($detail, $objRef);
            if ($this->orm['loadRelations'])
            {
               $objRef->enableNestedRelations();
               $this->orm['objectsWaitingForRelations'][] = $objRef;
            }
            else
            {
               $objRef->disableNestedRelations();
            }
            $objRef->orm['dbSync'] = true;
            $objRef->orm['isDirty'] = false;
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


   // This is part of ormLoad()
   // Add/Modify the primary query paramaters.
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


   // This is part of ormLoad()
   // Add an additional query to the db query queue.  Pass in the normal arguments required for the doSelect() method...
   private function additionalQuery($tables, $fields, $conditions, $order, $limit)
   {
         $newQueue['tables'] = $tables;
         $newQueue['fields'] = $fields;
         $newQueue['conditions'] = $conditions;
         $newQueue['order'] = null;
         $newQueue['limit'] = null;
         array_push($this->orm['queryQueue'], $newQueue);
   }


   // This is part of ormLoad()
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

      $objRef->orm['dbSync'] = true;
      $objRef->orm['isDirty'] = false;
      return $numFields;
   }


   // This is part of ormLoad()
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

      // Set conditions array in related object to include foreign key = local key value
      $conditions = array();
      $conditions[] = $key . " = " . $foreignKeyValue;
      $objRef->ormSetAutoConditions($conditions);

      // Set foreign key property in related object to my current value
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
   
   
// end ORM
}
