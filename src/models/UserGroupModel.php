<?php
namespace Craft;

craft()->requireEdition(Craft::Pro);

/**
 * User group model class.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2014, Pixel & Tonic, Inc.
 * @license   http://buildwithcraft.com/license Craft License Agreement
 * @link      http://buildwithcraft.com
 * @package   craft.app.models
 * @since     1.0
 */
class UserGroupModel extends BaseModel
{
	/**
	 * Use the translated group name as the string representation.
	 *
	 * @return string
	 */
	function __toString()
	{
		return Craft::t($this->name);
	}

	/**
	 * @return array
	 */
	protected function defineAttributes()
	{
		$attributes['id'] = AttributeType::Number;
		$attributes['name'] = AttributeType::String;
		$attributes['handle'] = AttributeType::String;

		return $attributes;
	}

	/**
	 * Returns whether the group has permission to perform a given action.
	 *
	 * @param string $permission
	 * @return bool
	 */
	public function can($permission)
	{
		if ($this->id)
		{
			return craft()->userPermissions->doesGroupHavePermission($this->id, $permission);
		}
		else
		{
			return false;
		}
	}
}
