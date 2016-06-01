<?php
// Indicium Database Library
// Copyright(C) 2006-2016 McDaniel Consulting, LLC.  All rights resvered.
// @author Craig McDaniel <craig@craigify.com>
//

namespace Indicium\QueryBuilders;

use Indicium\Connections\MySQLConnection;
use Indicium\Exceptions\QueryBuilderException;

class MySQLQueryBuilder extends QueryBuilder
{
   protected $lastQuery;

   // Constructor expects a MySQLConnection object that is ready to be used  
   function __construct(MySQLConnection $connection)
   {
	  parent::__construct();
	  $this->connection = $connection;
   }


   // Query the database and register the result resource internally.
   // @param string $query SQL query to execute.
   // @return resource Result resource, if you want to use it for something.
   public function query($query)
   {	  
      if ($this->getConnection()->checkConnection() == FALSE)
      {
         return FALSE;
      }

      if ($this->debugMode && $this->logger)
      {
		 $this->logger->debug($query);
      }

	  $this->lastQuery = $query;
      $res = mysqli_query($this->getConnection()->getHandle(), $query);
	  
	  if ($res === false)
	  {
		 $msg = "Query failed: " . mysqli_error($this->getConnection()->getHandle());

         if ($this->debugMode === true && $this->logger)
         {
			$this->logger->error($msg);
		 }

		 $e = new QueryBuilderException($msg);
		 $e->setQuery($query);
		 throw $e;
	  }
	  
      $this->registerResult($res);
      return $res;
   }

   
   public function beginTransaction()
   {
	  return mysqli_begin_transaction($this->getConnection()->getHandle());  
   }

   
   public function commitTransaction()
   {
	  return mysqli_commit($this->getConnection()->getHandle());
   }
   
   
   public function rollbackTransaction()
   {
	  return mysqli_rollback($this->getConnection()->getHandle());
   }

   
   public function fetchRow()
   {
      $row = mysqli_fetch_row($this->getResult());
      return $row;
   }


   public function fetchArray()
   {
      $row = mysqli_fetch_assoc($this->getResult());
      return $row;
   }


   public function fetchObject()
   {
      $obj = mysqli_fetch_object($this->getResult());
      return $obj;
   }


   public function fetchItem($key)
   {
      if (empty($key))
      {
         throw new \InvalidArgumentException("Key cannot be empty");
      }

      $res = $this->getResult();
      $row = $this->fetchArray();
	  
	  if (isset($row[$key]))
	  {
         return $row[$key];		 
	  }
	  else
	  {
		 $e = new QueryBuilderException("fetchItem did not find key '{$key}' in the first result row.");
		 $e->setQuery($this->lastQuery);
		 throw $e;
	  }
   }


   public function fetchResults($key=NULL)
   {
      $res = $this->getResult();
      $results = array();

      if ($key==NULL)
      {
         while ($row = $this->fetchArray())
         {
            $results[] = $row;
         }
      }
      else
      {
         while ($row = $this->fetchArray())
         {
            $results[$row[$key]] = $row;
         }

      }

   return $results;
   }


   // Deprecated
   public function affectedRows()
   {
      $aff_rows = @mysqli_affected_rows($this->getConnection()->getHandle());
      return $aff_rows;
   }


   // Deprecated
   public function numRows()
   {
      $num_rows = @mysqli_num_rows($this->getResult());
      return $num_rows;
   }
   
   
   // Return number of rows for last operation of any kind.
   public function countRows()
   {
      $aff_rows = @mysqli_affected_rows($this->getConnection()->getHandle());
      return $num_rows;	  
   }


   public function lastInsertId()
   {
      $query = "SELECT LAST_INSERT_ID()";
      $res = @mysqli_query($this->getConnection()->getHandle(), $query);
      $row = @mysqli_fetch_row($res);
      return $row[0];
   }


   // Generate an 'INSERT ... ON DUPLICATE KEY UPDATE' SQL query and execute it.
   // @throws QueryBuilderException
   public function doDuplicateKeyInsert($table, $inputVars)
   {
      $fields_insert = "";
      $fields_update = "";
      $values = "";

      if (!is_array($inputVars) || count($inputVars) < 1)
      {
		 throw new QueryBuilderException("doDuplicateKeyInsert() expects an array with key=value pairs as its second argument.");
      }

      foreach ($inputVars as $field => $value)
      {
         $value_converted = $this->convertValueToSQL($value);
         $fields_insert .= "{$field}, ";
         $fields_update .= "{$field} = {$value_converted}, ";
         $values .= "{$value_converted}, ";
      }
	  
      // remove trailing comma and space 
      $fields_insert = substr($fields_insert, 0, -2);
      $fields_update = substr($fields_update, 0, -2);
      $values = substr($values, 0, -2);

      $query = "INSERT INTO {$table} ({$fields_insert}) VALUES ({$values})
                ON DUPLICATE KEY UPDATE {$fields_update}
               ";

      $res = $this->query($query);

   return $res;
   }


   // Construct and execute an INSERT IGNORE statement.
   // @throws QueryBuilderException
   public function doInsertIgnore($table, $inputVars=array())
   {
      $fields = "";
      $values = "";

      if (!is_array($inputVars) || count($inputVars) < 1)
      {
		 throw new QueryBuilderException("doInsertIgnore() expects an array with key=value pairs as its second argument.");
      }

      foreach ($inputVars as $field => $value)
      {
         $fields .= "{$field}, ";
         $values .= $this->convertValueToSQL($value) . ", ";
      }

      /* remove trailing comma and space */
      $fields = substr($fields, 0, -2);
      $values = substr($values, 0, -2);

      $query = "INSERT IGNORE INTO {$table} ({$fields}) VALUES ({$values})";
      $res = $this->query($query);

   return $res;
   }


   // MYSQL's method of making unique inserts is INSERT IGNORE.  Use it.
   public function doUniqueInsert($table, $inputVars=array())
   {
      return $this->doInsertIgnore($table, $inputVars);
   }


// end   
}
