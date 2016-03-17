<?php
// Indicium Database Library
// Copyright(C) 2016 McDaniel Consulting, LLC.  All rights resvered.
// @author Craig McDaniel <craig@craigify.com>
//
// Database Factory.  Use this class to autoload and automatically create Connection and QueryBuilder
// objects for you, ready to use.
//

namespace Indicium;

class DatabaseFactory
{
	private $autoload;

	
	function __construct()
	{
		$this->autoload = true;
	}

	
	// Set the autoload feature on or off.  While on, this class will automatically include() the proper
	// Connection and QueryBuilder files.  When off, the autoloading is up to you.  Default to true.
	// @param boolean $val Pass in TRUE to turn on autoloading, FALSE to turn it off.
	public function setAutoload($val)
	{
		if (!is_bool($val))
		{
			throw new \InvalidArgumentException;
		}
		
		$this->autoload = $val;
	}

	
	// Create a new Connection and QueryBuilder object, ready to use.  Besides $type, which must be
	// specified so this method knows what kind of connection and query builder to create, all other
	// arguments are passed on to the Connection class constructor.  You'll generally need to specify,
	// including type, hostname, username, password and potentially other arguments, like the default
	// database or any specifics for the underlying PHP db driver.
	//
	// For MySQL (everything after "mysql" gets passed to the MySQLConnection constructor):
	// create("mysql", "hostname", "username", "password", "defaultdb", optional $args)
	//
	// @param string $type Case sensitive type string, e.g. "MySQL" or "PgSQL"
	// @param mixed $args,... Additional arguments to pass to underlying constructor
	// @return object Returns a new QueryBuilder object, like MySQLQueryBuilder
	public function create($type)
	{
		$args = func_get_args();
		array_shift($args); // Remove the first index since we also have it in $type
		
		$connection = $this->newConnection($type, $args);
		$queryBuilder = $this->newQueryBuilder($type, $connection);
		
		return $queryBuilder;
	}
	
	
	// Dynamically instantiate a QueryBuilder object of $type
	// @param string $type Case sensitive QueryBuilder type string. E.g. "MySQL" for "MySQLQueryBuilder"
	// @param Connection $connection Connection object that corresponds to the query builder.
	// @return Object Return the new QueryBuilder object
	private function newConnection($type, $args)
	{
		if ($this->autoload === true)
		{
			require_once("Connections/Connection.php");
			$filename = "Connections/{$type}Connection.php";
			$include = @include_once($filename);
			
			if ($include === false)
			{
				throw new \DomainException("Could not create Connection of type '{$type}': Invalid type specified.");
			}
		}
		else
		{
			if (!class_exists("QueryBuilder"))
			{
				throw new \DomainException("The Connection abstract base class was not in scope.  Did you properly load the class file?");				
			}

			if (!class_exists($className))
			{
				throw new \DomainException("Could not create Connection of type '{$type}'. The specified class '{$className}' was not in scope.  Did you properly load the class file?");				
			}
		}

		$className = "Indicium\\Connections\\" . $type . "Connection";
		$connection = $this->createNew($className, $args);
		
		if (!$connection)
		{
			throw new \DomainException("Could not create Connection of type '{$type}': createNew failed.");
		}
		
		return $connection;
	}
	
	
	// Dynamically instantiate a QueryBuilder object of $type
	// @param string $type Case sensitive QueryBuilder type string. E.g. "MySQL" for "MySQLQueryBuilder"
	// @param Connection $connection Connection object that corresponds to the query builder.
	// @return Object Return the new QueryBuilder object
	private function newQueryBuilder($type, $connection)
	{
		if ($this->autoload === true)
		{
			require_once("QueryBuilders/QueryBuilder.php");
			$filename = "QueryBuilders/{$type}QueryBuilder.php";
			$include = @include_once($filename);
			
			if ($include === false)
			{
				throw new \DomainException("Could not create QueryBuilder of type '{$type}': Invalid type specified.");				
			}
		}
		else
		{
			if (!class_exists("QueryBuilder"))
			{
				throw new \DomainException("The QueryBuilder abstract base class was not in scope.  Did you properly load the class file?");				
			}

			if (!class_exists($className))
			{
				throw new \DomainException("Could not create QueryBuilder of type '{$type}'.  The specified class '{$className}' was not in scope.  Did you properly load the class file?");				
			}
		}		

		$className = "Indicium\\QueryBuilders\\" . $type . "QueryBuilder";
		$queryBuilder = $this->createNew($className, array($connection));
		
		if (!$queryBuilder)
		{
			throw new \DomainException("Could not create QueryBuilder of type '{$type}': createNew failed.");
		}
		
		return $queryBuilder;
	}
	
	
	// Dynamically instantiate an object and return it.
	// @param string $className The full name of the class, including namespace
	// @param array $args Array of arguments to pass to the constructor
	// @return Object Returns the newly instantiated object of type $className
	private function createNew($className, $args=array())
	{
		try
		{
			$reflection_class = new \ReflectionClass($className);
			return $reflection_class->newInstanceArgs($args);			
		}
		catch (ReflectionException $e)
		{
			return FALSE;
		}
	}

	
// end Factory	
}


?>