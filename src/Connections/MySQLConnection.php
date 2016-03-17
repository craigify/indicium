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
   * @param args     (array)    Optional array of arguments.
   *
   * Optional arguments are:
   *  newlink      - PHP will reuse connections.  Set this to 1 to guarantee a new connection.
   *  persistent   - Use persistent connections.
   *  force_escape - Something.
   * 
   */
  
   function __construct ($hostname, $login="", $password="", $database="", $args=array())
   {
      $this->server    = $hostname;
      $this->login     = $login;
      $this->password  = $password;
      $this->database  = $database;

      if (isset($args['persistent']))
        $this->persistent = TRUE;
      else
        $this->persistent = FALSE;

      if (isset($args['newlink']))
        $this->newlink = TRUE;
      else
        $this->newlink = FALSE;

      $this->connect();
   }


  /* Establish a connection to the MySQL server and select appropriate database.
   */

   public function connect()
   {
      if ($this->persistent)
        $handle = mysql_pconnect($this->server, $this->login, $this->password);
      else
        $handle = mysql_connect($this->server, $this->login, $this->password, $this->newlink);

      if ($handle == FALSE)
      {
         return FALSE;
      }

      $res = mysql_select_db($this->database, $handle);

      if ($res == FALSE)
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
         mysql_close($handle);

         $this->dbhandle = NULL;
         $this->isconnected = 0;
      }
   }
   
   
/* end mysql class */
}



?>
