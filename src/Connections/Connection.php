<?php
// Indicium Database Library
// Copyright(C) 2006-2016 McDaniel Consulting, LLC.  All rights resvered.
// @author Craig McDaniel <craig@craigify.com>

namespace Indicium\Connections;

abstract class Connection
{
   protected $server;
   protected $login;
   protected $password;
   protected $database;
   protected $dbhandle;
   protected $dbresult;
   protected $isconnected;


   // Make a connnection to the remote SQL server and return TRUE/FALSE.  This should set the connection
   // handler with this->setHandler().  The constructor should call this method automatically.
   //
   // @return (boolean) TRUE/FALSE signifying connection status.
   abstract public function connect();


   // Close the database connection, rendering the object useless.   Good if you need to free up resources.
   abstract public function close();


   // Register/save the database resource handle internally.
   // @param resource $dbhandle The database resource to store internally
   protected function registerHandle($dbhandle)
   {
      if ($dbhandle != FALSE)
      {
         $this->isconnected = 1;
         $this->dbhandle = $dbhandle;
      }
      else
      {
         $this->isconnected = 0;
      }
   }
   
   
   // Get the resource handle for the current connection.
   public function getHandle()
   {
      return $this->dbhandle;
   }


   // Get the last stored result resource handle.
   public function getResult()
   {
      return $this->dbresult;
   }


   // Check if we have a connection to the server and attempt to connect if not.
   //
   // Return TRUE if we have a connection or a connection was just made, or return FALSE if no connection could be established.
   //
   public function checkConnection()
   {
      if ($this->isconnected == 1)
      {
         if ($this->dbhandle != FALSE)
         {
            return TRUE;
         }
         else
         {
            $res = $this->connect();
         }
      }
      else
      {
         $res = $this->connect();
      }

      if ($res == 0)
      {
         return TRUE;
      }
      else
      {
         return FALSE;
      }
   }


   // Return TRUE if connected to db server, or FALSE if not
   public function isConnected()
   {
      if ($this->isconnected == 1)
      {
         if ($this->dbhandle != FALSE)
         {
            return TRUE;
         }
         else
         {
            return FALSE;
         }
      }
      else
      {
         return FALSE;
      }
   }

   
// end class
}


?>
