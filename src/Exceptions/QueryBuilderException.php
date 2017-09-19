<?php
// Indicium Database Library
// Copyright(C) 2016 McDaniel Consulting, LLC.  All rights resvered.
// @author Craig McDaniel <craig@craigify.com>
//
// Exception class for Query Builders
//

namespace Indicium\Exceptions;

class QueryBuilderException extends IndiciumException
{
	protected $query = "";

	// Constructor
	public function __construct($message, $code=0, \Exception $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
	
	// Set the query that generated the exception
	// @param string $query
	public function setQuery($query)
	{
		$this->query = $query;
	}
	
	// Get the query that generated the exception, if available.
	// @return string
	public function getQuery()
	{
		return $this->query;
	}
}