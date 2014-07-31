<?php
namespace Craft;

/**
 * Class TemplateLoaderException
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2014, Pixel & Tonic, Inc.
 * @license   http://buildwithcraft.com/license Craft License Agreement
 * @link      http://buildwithcraft.com
 * @package   craft.app.etc.errors
 * @since     1.0
 */
class TemplateLoaderException extends \Twig_Error_Loader
{
	/**
	 * @var string
	 */
	public $template;

	/**
	 * @param string $template
	 *
	 * @return TemplateLoaderException
	 */
	function __construct($template)
	{
		$this->template = $template;
		$message = Craft::t('Unable to find the template “{template}”.', array('template' => $this->template));
		Craft::log($message, LogLevel::Error);

		parent::__construct($message);
	}
}
