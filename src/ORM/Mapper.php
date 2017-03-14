<?php
// Indicium Database Library
// Copyright(C) 2006-2016 McDaniel Consulting, LLC.  All rights resvered.
// @author Craig McDaniel <craig@craigify.com>
//
// Utility class to map ORM objects to plain PHP data structures devoid of any ORM leftovers.  It can
// map a single ORM object, or array of objects.  It will also recursively map any loaded relations
// in each object automatically.
//

namespace Indicium\ORM;

class Mapper
{
	const TO_ARRAY = 1;
	const TO_OBJECT = 2;


	// Map input to a standard PHP object, or array of objects.
	static public function toObject($input)
	{
		return self::map($input, self::TO_OBJECT);
	}
	
	
	// Map input to a standard PHP associative array, or array of assoc. arrays.
	static public function toArray($input)
	{
		return self::map($input, self::TO_ARRAY);
	}


	// If you prefer to call \Indicium\ORM\Mapper::map() directly, then do so.  Just pass in
	// the input data and the output format.
	static public function map($input, $format=self::TO_ARRAY)
	{
		if ($input instanceof ORM)
		{
			// Get any properties on the object that is not mapped to db, then merge that with the
			// values returned from getFields()
			$props = [];
			foreach ($input as $key => $value) $props[$key] = $value;
			$map = array_merge($props, $input->getFields());

			if ($format == self::TO_OBJECT)
			{
				$map = (object)$map;
			}
			
			self::mapRelations($input, $format, $map);
			return $map;
		}
		
		// Trick for speed. Check if input is an array.  Cast to array and check if both values are
		// identical: http://php.net/manual/en/function.is-array.php#98156
		else if ((array)$input === $input)
		{			
			$mapArr = array();
			
			foreach ($input as $orm)
			{
				$newMap = null;
				
				if ($orm instanceof ORM)
				{
					// Get any properties on the object that is not mapped to db, then merge that
					// with the values returned from getFields()
					$props = [];
					foreach ($orm as $key => $value) $props[$key] = $value;
					$newMap = array_merge($props, $orm->getFields());
					
					if ($format == self::TO_OBJECT)
					{
						$newMap = (object)$newMap;
					}

					self::mapRelations($orm, $format, $newMap);
					$mapArr[] = $newMap;
				}
			}
			
			return $mapArr;
		}
		
		else
		{
			// Invalid input object.
		}
	}
	
	
	// Map the relations of the input object
	static private function mapRelations($input, $format, &$map)
	{
		if (!$input instanceof ORM)
		{
			// error out
		}
		
		$relations = $input->getRelations();
		
		foreach ($relations as $shortClassName => $orm)
		{
			if ($format == self::TO_OBJECT)
			{
				$map->{$shortClassName} = self::map($orm, $format);

				// If $orm is an array of objects, keep it an array of objects. map() will have
				// converted everything into an object, which is good, but we want $map->{$shortClassName}
				// only to be an array of objects now, so we just cast it below.
				if ((array)$orm === $orm)
				{
					$map->{$shortClassName} = (array)$map->{$shortClassName};					
				}
			}
			else
			{
				$map[$shortClassName] = self::map($orm, $format);
			}
		}
	}

	
// end mapper
}