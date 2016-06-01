<?php
// Indicium Database Library
// Copyright(C) 2006-2016 McDaniel Consulting, LLC.  All rights resvered.
// @author Craig McDaniel <craig@craigify.com>
//
//
// When initializing this class, you can pass some additional data to the constructor:
//   args['persistent']      Establish a persistent connection to the database.
//   args['newlink']         Force PHP to establish a new connection to the database no matter what.
//   args['force_escape']    Force escaping of all data without checking if data has already been escaped.
//                           This applies to the do() methods only.
//

namespace Indicium\Connections;

use Indicium\Connections\Connection;

class MySQLConnection extends Connection
{


  /* Create a new database connection and return an object representing that connection.
   * @param hostname (string)   Hostname of database server
   * @param login    (strring)  Login name to authenticate to database. server.
   * @param password (string)   Password for database server.
   * @param args     (array)    Optional array of arguments.  Not used for now.  Placeholder.
   * 
   */
  
   function __construct ($hostname, $login="", $password="", $database="", $args=array())
   {
      $this->server    = $hostname;
      $this->login     = $login;
      $this->password  = $password;
      $this->database  = $database;
      $this->connect();
   }


  /* Establish a connection to the MySQL server and select appropriate database.
   */

   public function connect()
   {
      $handle = mysqli_connect($this->server, $this->login, $this->password, $this->database);

      if ($handle == FALSE)
      {
         return FALSE;
      }

      $this->registerHandle($handle);

   return TRUE;
   }
   
   
   // Close the database connection.  This will render the connection in-operative (obviously).
   public function close()
   {
      if ($this->checkConnection())
      {
         $handle = $this->getHandle();
         mysqli_close($handle);

         $this->dbhandle = NULL;
         $this->isconnected = 0;
      }
   }
   
   
/* end mysql class */
}



?>
