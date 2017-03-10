<?php
// Indicium Database Library
// Copyright(C) 2006-2016 McDaniel Consulting, LLC.  All rights resvered.
// @author Craig McDaniel <craig@craigify.com>
//
//
// Base class for all SQL interfaces.  Specific database classes (like the MySQL and PostgreSQL) class
// extend this base class and implement the specifics for the particular database type.  Some commonly
// available methods for all databases are defined in here.
//
// Method                Description
// ----------------------------------------------------------------------------------------------------------
// connect()             Connnect to database.  Automatic in higher level classes upon object initialization.
// query()               Query the database.
// fetchRow()            Fetch numerical index of result data.
// fetchArray()          Fetch associative index of result data in key=> value pairs.
// fetchResults()        Fetch entire resultset as 2D array.  Can be ordered.
// countRows()           Returns the number of rows affected by the last operation.
// lastInsertId()        Returns last last auto_increment, identity, etc...
// queryFetchRow()       Query the database and fetch one row of the result as a numeric array.
//                       Use when you only want to retrieve a single row of a resultset, or if you
//                       resultset consists of only a single row.
// queryFetchArray()     Same as above, except returns assoc array of key => value pairs.
// queryFetchResults()   Same as above, except returns entire resultset as 2D array.  Can be ordered.
// queryFetchItem()      Useful to query and fetch a single field name out of the first row of results.
// forceEscape()         ALWAYS escape input data without checking if data has been previously escaped if set
//                       to TRUE.  Set to FALSE to check if data has been escaped already (default behavior).
// e()                   Neat little string escape method.  Checks if data is already escaped first.
// generateWhere()       Generate a WHERE clause from a specified conditions array.
//
// ** Methods for automatic generation of SQL queries:
//
// doInsert()            Construct an insert statement.
// doInsertIgnore()      Shortcut to construct an INSERT IGNORE statement.
// doUpdate()            Construct an update statement.
// doDelete()            Construct a delete statement.
// doSelect()            Construct a select statement.  Handles JOINS also.
//

namespace Indicium\QueryBuilders;

use Indicium\Exceptions\QueryBuilderException;

abstract class QueryBuilder
{
   protected $connection;
   protected $dbresult;
   protected $forceEscape;
   protected $debugMode;
   protected $logger;


   function __construct()
   {
      $this->debugMode = false;
      $this->forceEscape = false;
   }
   

   
   // Send a query to the DB and return result.  Save result internally.
   // @param (string)  $query  SQL query to execute.
   //@return (result) Return a result resource, or FALSE if error.   
   abstract public function query($query);


   // Fetch a single row from the DB and return as an associative array.
   // @return (array) hash, or assoc. array of results.
   abstract public function fetchRow();


   // Fetch rows from the DB.  When no more rows are available to be returned return FALSE.
   // @return (mixed) Array of result data, or FALSE if no rows.
   abstract public function fetchArray(); 


   // Fetch rows from the DB as an object.  When no more rows are available to be returned return FALSE.
   // @return (object|boolean) Object with field names as properties, or FALSE if no rows.
   abstract public function fetchObject();


   //Fetch a single item from resultset.
   // @param  (string)  $key Pass in key (column name) to fetch from resultset.
   // @return (mixed)   single item from result data.
   abstract public function fetchItem($key);



  /* Return the entire resultset as a 2D associative array.  Key represents the field you wish
   * to use on the top level array.  If no key is specified, the array is formed sequentially
   * starting with zero, as usual.
   *
   * Method needs to return data in the following format:
   * result[0]['row1'] = "value";
   * result[0]['row2'] = "value";
   * result[1]['row1] = "value";
   * result[1][row2'] = "value";
   *
   * @param  (string) $key Key represents the field you wish to use on the top level array.
   * @return (array)  array of hashes (or 2D assoc. array) of results.
   */

   abstract public function fetchResults($key=NULL);



  /* Return the number of rows by the last operation.
   * @return (int) number of rows.
   */

   abstract public function countRows();


  /* Return the ID of the last INSERT (AUTO_INCREMENT in MySQL and @@IDENTITY in MSSQL)
   * @return (mixed) value.
   */

   abstract public function lastInsertId();



  /* This is an attempt to construct a low-level UNIQUE INSERT command across multiple SQL flavors.
   * It is defined as an abstract method here so that higher level classes implement it in the terms
   * of the SQL engine being represented.
   * 
   * @param (string)  $table      Name of table that you are inserting into.
   * @param (array)   $inputVars  Array of key=>value pairs, where key represents field name and value is data to insert.
   */

   abstract public function doUniqueInsert($table, $inputVars=array());



   ////////////////////////////////////////////////////////////////////////////////////////////////////
   // End abstract methods.  The following are methods shared by all higher level database classes.
   ////////////////////////////////////////////////////////////////////////////////////////////////////



   // Inject a PSR-3 compatible logger.
   // @param object $logger Logger object
   public function setLogger($logger)
   {
      $this->logger = $logger;
   }
   
   
   public function setDebugMode($mode)
   {
      $this->debugMode = $mode;
   }
   
   
   // Query and fetchResults() in one swoop.
   public function queryFetchRow($query)
   {
      $this->query($query);
      return $this->fetchRow();
   }


   // Query and fetchArray() in one swoop.
   public function queryFetchArray($query)
   {
      $this->query($query);
      return $this->fetchArray();
   }


   //Query and fetchObject() in one swoop.
   public function queryFetchObject($query)
   {
      $this->query($query);
      return $this->fetchObject();
   }


   // Query and fetchItem() in one swoop.
   public function queryFetchItem($query, $key)
   {
      $this->query($query);
      return $this->fetchItem($key);
   }


   // Query and fetchResults() in one swoop.
   public function queryFetchResults($query, $key=NULL)
   {
      $this->query($query);
      return $this->fetchResults($key);
   }



  /* Construct a SQL SELECT statement.  If fields array is empty, SELECT * is assumed.  Specify additional
   * SQL constructs in $where, $order, and $limit as you see fit.
   *
   * Conditions array is a special formatted array that gets parsed by the generateWhere() method.  Please
   * see that method for the correct format.
   *
   * @param (mixed) $tables      Specify a single table name, or an array of tables names when JOINING.
   * @param (array) $fields      Assoc. array of key value pairs.  Key is db field, value represents what to return AS
   * @param (array) $conditions  Numerically indexed array of conditions.  See generateWhere() for proper format.
   */

   public function doSelect($tables=array(), $fields=array(), $conditions=array(), $order="", $limit="")
   {
      $fieldsComma = "";

      /* Formulate fields in query */
      if (count($fields) > 0)
      {
         foreach ($fields as $key => $value)
         {
            if (is_numeric($key))
              $fieldsComma .= "{$value}, ";
            else
              $fieldsComma .= "{$key} AS $value, ";
         }

         /* remove trailing comma and space */
         $fieldsComma = substr($fieldsComma, 0, -2);
      }
      else
      {
         $fieldsComma = "*";
      }


      /* Determine if we are selecting from multiple tables or not. If so, formulate correct joins as specified */
      if (is_array($tables))
      {
         /* Determine first table to SELECT from */
         if (isset($tables[0]))
         {
            $firstTable = $tables[0];
         }
         else
         {
            $keys = array_keys($tables);
            $firstTable = $keys[0];
         }

         $query = "SELECT {$fieldsComma} FROM {$firstTable}";

         foreach ($tables as $table => $joinData)
         {
            /* Match extended JOIN syntax <JOIN TYPE> <LOCALTBL>.<FIELD> = <REMOTETBL>.<FIELD> */
            if (preg_match("/(INNER JOIN|LEFT JOIN)(.+?)\.(.+?)\s+=\s+(.+?)\.(.+)/", $joinData, $matches))
            {
               $joinType = $matches[1];
               $localTable = $matches[2];
               $localField = $matches[3];
               $foreignTable = $matches[4];
               $foreignField = $matches[5];

               $query .= "\n {$joinType} {$table} ON {$localTable}.{$localField} = {$foreignTable}.{$foreignField} ";
            }

            /* Match shorthand syntax of <JOIN TYPE> <TABLE>.<FIELD NAME> */
            else if (preg_match("/(INNER JOIN|LEFT JOIN)\s(.+?)\.(.+)/", $joinData, $matches))
            {
               $joinType = $matches[1];
               $localTable = $table;
               $localField = $matches[3];
               $foreignTable = $matches[2];
               $foreignField = $matches[3];

               $query .= "\n {$joinType} {$table} ON {$table}.{$localField} = {$foreignTable}.{$foreignField} ";
             }

         }
      }

      /* Table is a string.  We won't be joining any additional tables */
      else
      {
         $query = "SELECT {$fieldsComma} FROM {$tables}";
      }

      /* Generate where clause from conditional array */
      $where = $this->generateWhere($conditions);
      $query .= "\n {$where} ";

      /* order clause */
      if (!empty($order))
        $query .= "\n ORDER BY {$order}";

      /* limit clause */
      if (!empty($limit))
        $query .= "\n LIMIT {$limit}";

      /* Perform the actual query */
      $res = $this->query($query);

   return $res;
   }


   // I think the following are pretty self explainatory.  One-swoop methods that mirror the queryFetch methods.
   public function doSelectFetchArray($tables=array(), $fields=array(), $conditions=array(), $order="", $limit="")
   {
      $this->doSelect($tables, $fields, $conditions, $order, $limit);
      return $this->fetchArray();
   }


   public function doSelectFetchObject($tables=array(), $fields=array(), $conditions=array(), $order="", $limit="")
   {
      $this->doSelect($tables, $fields, $conditions, $order, $limit);
      return $this->fetchObject();
   }


   public function doSelectFetchRow($tables=array(), $fields=array(), $conditions=array(), $order="", $limit="")
   {
      $this->doSelect($tables, $fields, $conditions, $order, $limit);
      return $this->fetchRow();
   }


   public function doSelectFetchResults($tables=array(), $fields=array(), $conditions=array(), $order="", $limit="")
   {
      $this->doSelect($tables, $fields, $conditions, $order, $limit);
      return $this->fetchResults();
   }


   public function doSelectFetchItem($tables=array(), $key, $conditions=array())
   {
      $this->doSelect($tables, array($key), $conditions);
      return $this->fetchItem($key);
   }


   // Construct a SQL INSERT statement and execute it.  This should work with any compliant SQL92 engine.
   public function doInsert($table, $inputVars=array())
   {
      $fields = "";
      $values = "";

      if (!is_array($inputVars) || count($inputVars) < 1)
      {
		 throw new QueryBuilderException("doInsert() expects second argument to be an array");
      }

      foreach ($inputVars as $field => $value)
      {
         $fields .= "`{$field}`, ";
         $values .= $this->convertValueToSQL($value) . ", ";
      }

      // remove trailing comma and space
      $fields = substr($fields, 0, -2);
      $values = substr($values, 0, -2);

      $query = "INSERT INTO {$table} ({$fields}) VALUES ({$values})";
      $res = $this->query($query);

   return $res;
   }



   // Construct a SQL UPDATE statement and execute it.
   public function doUpdate($table, $inputVars, $conditions=array(), $limit="")
   {
      $fields = "";

      if (!is_array($inputVars) || count($inputVars) < 1)
      {
		 throw new QueryBuilderException("doUpdate() expects second argument to be an array");
      }

      foreach ($inputVars as $field => $value)
      {
         $fields .= "`{$field}` = " . $this->convertValueToSQL($value) . ", ";
      }

      // remove trailing comma and space
      $fields = substr($fields, 0, -2);

      $query = "UPDATE {$table} SET {$fields} " . $this->generateWhere($conditions) . " " . $limit;
      $res = $this->query($query);

   return $res;
   }



  /* Construct and execute a SQL DELETE statement.   I would be generally careful of auto-generating WHERE
   * clauses while deleting, and hence take caution when using this method.
   */

   public function doDelete($table, $conditions=array(), $limit="")
   {
       if (empty($conditions))
       {
          return FALSE;
       }

       $where = $this->generateWhere($conditions);

       if (empty($where))
       {
          return FALSE;
       }

      $query = "DELETE FROM {$table} {$where} {$limit}";
      $res = $this->query($query);

   return $res;
   }



  /* Pass TRUE to force the library to addslashes() to all SQL data no matter what.  Try using this when
   * working with binary data.
   *
   * Pass FALSE to attempt to check if data has been previously escaped before calling addslashes() to
   * try to avoid double escaping.  This usually works, but can cause issues with binary data, for example.
   * It is also the default behaviour since it works 99% of the time.
   */

   public function forceEscape($val)
   {
      if ($val === TRUE)
      {
         $this->forceEscape = TRUE;
      }

      if ($val === FALSE)
      {
         $this->forceEscape = FALSE;
      }
   }



  /* Escape a string to be sent to the database.  Check for \' in input data in an attempt to avoid
   * double escaped data.
   *
   * If the alwaysEscape flag is set via the method forceEscape(TRUE) then I will always
   * escape the string NO MATTER WHAT.
   */

   public function e($str)
   {
      if ($this->forceEscape === TRUE)
        return addslashes($str);

      if (preg_match("/\\\'/", $str))
      {
         return $str;
      }
      else
      {
         return addslashes($str);
      }
   }



  /* Generate a WHERE clause and automatically perform SQL escaping.
   *
   * Pass an array of strings formatted like examples below to this method.  The first index
   * will generate a WHERE clause, and each subsequent index will generate an AND clause.
   *
   * NOTE!! If you pass a string to $conditions, this will assume you are passing a valid WHERE
   * clause written out and will simply pass it along _UNMODIFIED_  No escaping! Hello!
   *
   * Array will be processed sequentially, and must contain strings in the following format:
   * "fieldName Operator Value"
   *
   * example:
   *
   *  conditions[] = "field = value"              creates:     WHERE field = 'value'
   *  conditions[] = "field > value"              creates:     AND field > 'value'
   *  conditions[] = "Table.field LIKE %Craig"    creates:     AND Table.field LIKE '%Craig'
   *  and so on...
   *
   * Handles the following operators:
   * '>'   '<'    '='   '<>'   '>='   '<='   'LIKE'   'NOTLIKE'
   */

   public function generateWhere($conditions=array())
   {
      /* Do we have a literal WHERE clause? */
      if (is_string($conditions))
      {
         return $conditions;
      }

      $where = "";
      $count = 0;

      foreach ($conditions as $idex => $detail)
      {
         if ($count == 0)
           $name = "WHERE";
         else
           $name = "AND";

         $detail = ltrim(rtrim($detail));

         // Detect a literal condition statement in the format of L=statement.  
         if (substr($detail, 0, 2) == "L=")
         {
            $where .= " " . substr($detail, 2) . " ";
         }  

         // match 'Database.Table.field <O> value' OR  'Table.field <O> value'  OR  'field <O> value'
         // where <O> is a supported SQL operator
         else if (preg_match("/^([a-zA-Z0-9-_]+?\.[a-zA-Z0-9-_]+?\.[a-zA-Z0-9-_]+?|[a-zA-Z0-9-_]+?\.[a-zA-Z0-9-_]+?|[a-zA-Z0-9-_]+?)\s{1}(.+?)\s{1}(.+)/", $detail, $matched))
         {
            $field = $matched[1];
            $operator = $matched[2];
            $value = $this->convertValueToSQL($matched[3]);

            switch ($operator)
            {
               case "=":
                 $where .= " $name $field = $value ";
               break;

               case "=S":
                 $value = str_replace("'", "", $value);
                 $where .= " $name $field = ( $value ) ";
               break;

               case "<>":
                 $where .= " $name $field <> $value ";
               break;

               case "<>S":
                 $value = str_replace("'", "", $value);
                 $where .= " $name $field <> ( $value ) ";
               break;

               case ">":
                 $where .= " $name $field > $value ";
               break;

               case ">S":
                 $value = str_replace("'", "", $value);
                 $where .= " $name $field > ( $value ) ";
               break;

               case "<":
                 $where .= " $name $field < $value ";
               break;

               case "<S":
                 $value = str_replace("'", "", $value);
                 $where .= " $name $field < ( $value ) ";
               break;

               case ">=":
                 $where .= " $name $field >= $value ";
               break;

               case ">=S":
                 $value = str_replace("'", "", $value);
                 $where .= " $name $field => ( $value ) ";
               break;

               case "<=":
                 $where .= " $name $field <= $value ";
               break;

               case "<=S":
                 $value = str_replace("'", "", $value);
                 $where .= " $name $field =< ( $value ) ";
               break;

               case "LIKE":
                 $where .= " $name $field LIKE $value ";
               break;

               case "LIKES":
                 $value = str_replace("'", "", $value);
                 $where .= " $name $field LIKE ( $value ) ";
               break;

               case "NOTLIKE":
                 $where .= " $name $field NOT LIKE $value ";
               break;

               case "NOTLIKES":
                 $value = str_replace("'", "", $value);
                 $where .= " $name $field NOT LIKE ( $value ) ";
               break;

               default:
                  //error(E_WARNING, "Invalid operator '$operator' when building WHERE clause.");
               break;
            }
         }

         $count++;

      /* end foreach */
      }

   return $where;
   }



  /* Used by the do functions.  Takes in input variable, detects its type, and returns a value to be
   * inserted into an SQL statement.  Most importantly, this method is responsible for automatically
   * escaping data types to avoid SQL injection attacks.
   *
   * @param input (mixed) Input variable
   * @return output (mixed) Output variable converted to SQL plaintext, and possibly escaped.
   */

   public function convertValueToSQL($input)
   {
      /* Quote input depending on type */
      if     (is_string($input))      $output = "'" . $this->e($input) . "'";
      elseif (is_int($input))         $output = "'" . $this->e($input) . "'";
      elseif (is_float($input))       $output = "'" . $this->e($input) . "'";
      elseif ($input == "")           $output = "''";
      elseif ($input === TRUE)        $output = "TRUE";
      elseif ($input === FALSE)       $output = "FALSE";
      elseif (is_null($input))        $output = "NULL";
      elseif  (stristr($field, "("))   $values .= "$value, ";

      /* In case the following were passed as strings, make sure they are not quoted. */
      elseif (strtoupper($input) == "TRUE")   $output = "TRUE";
      elseif (strtoupper($input) == "FALSE")  $output = "FALSE";
      elseif (strtoupper($input) == "NULL")   $output = "NULL";

      /* Just in case we ever get here, just return input. Not very useful, but last resort */
      else $output = $input;

   return $output;
   }


   // Register a result returned from a query() statement.
   protected function registerResult($dbresult)
   {
      if ($dbresult != FALSE)
      {
         $this->dbresult = $dbresult;
      }
   }

   
   // Get the last stored result resource handle.
   public function getResult()
   {
      return $this->dbresult;
   }


   public function getConnection()
   {
      return $this->connection;
   }


// end class
}
