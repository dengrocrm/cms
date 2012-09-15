<?php
namespace Blocks;

/**
 * Block base class
 */
abstract class BaseBlock extends BaseComponent implements IBlock
{
	protected $componentType = 'Block';

	/**
	 * Returns the content column type.
	 *
	 * @return string
	 */
	public function defineContentAttribute()
	{
		return AttributeType::String;
	}

	/**
	 * Returns the block's input HTML.
	 *
	 * @abstract
	 * @param string     $handle
	 * @param mixed      $value
	 * @return string
	 */
	abstract public function getInputHtml($handle, $value);
}
