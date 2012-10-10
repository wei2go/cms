<?php
namespace Blocks;

/**
 * EntryTag model class
 *
 * Used for transporting entry tag data throughout the system.
 */
class EntryTagModel extends BaseModel
{
	/**
	 * Use the entry tag name as its string representation.
	 *
	 * @return string
	 */
	function __toString()
	{
		return $this->name;
	}

	public function defineAttributes()
	{
		$attributes['name'] = AttributeType::String;
		return $attributes;
	}
}
