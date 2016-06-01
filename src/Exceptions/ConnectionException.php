<?php
// Indicium Database Library
// Copyright(C) 2016 McDaniel Consulting, LLC.  All rights resvered.
// @author Craig McDaniel <craig@craigify.com>
//
// Exception class for Connections
//

namespace Indicium\Exceptions;

class ConnectionException extends IndiciumException
{
	// Constructor
	public function __construct($message, $code=0, Exception $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}

}