<?php
/**
*
* @package phpBB3
* @copyright (c) 2005 phpBB Group, sections (c) 2001 ispi of Lincoln Inc
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* @todo
* IMG_ for image substitution?
* {IMG_[key]:[alt]:[type]}
* {IMG_ICON_CONTACT:CONTACT:full} -> $user->img('icon_contact', 'CONTACT', 'full');
*
* More in-depth...
* yadayada
*/

/**
* Base Template class.
* @package phpBB3
*/
class phpbb_template
{
	/**
	* @var phpbb_template_context Template context.
	* Stores template data used during template rendering.
	*/
	private $context;

	/**
	* @var string Path of the cache directory for the template
	*/
	public $cachepath = '';

	/**
	* @var string phpBB root path
	*/
	private $phpbb_root_path;

	/**
	* @var phpEx PHP file extension
	*/
	private $phpEx;

	/**
	* @var phpbb_config phpBB config instance
	*/
	private $config;

	/**
	* @var user current user
	*/
	private $user;

	/**
	* @var locator template locator
	*/
	private $locator;

	/**
	* Constructor.
	*
	* @param string $phpbb_root_path phpBB root path
	* @param user $user current user
	* @param phpbb_template_locator $locator template locator
	*/
	public function __construct($phpbb_root_path, $phpEx, $config, $user, phpbb_template_locator $locator)
	{
		$this->phpbb_root_path = $phpbb_root_path;
		$this->phpEx = $phpEx;
		$this->config = $config;
		$this->user = $user;
		$this->locator = $locator;
	}

	/**
	* Set template location based on (current) user's chosen style.
	*/
	public function set_template()
	{
		$style_name = $this->user->theme['template_path'];

		$relative_template_root = $this->relative_template_root_for_style($style_name);
		$template_root = $this->phpbb_root_path . $relative_template_root;
		if (!file_exists($template_root))
		{
			trigger_error('template locator: Template path could not be found: ' . $relative_template_root, E_USER_ERROR);
		}

		if ($this->user->theme['template_inherits_id'])
		{
			$fallback_template_path = $this->phpbb_root_path . $this->relative_template_root_for_style($this->user->theme['template_inherit_path']);
		}
		else
		{
			$fallback_template_path = null;
		}

		return $this->set_custom_template($template_root, $style_name, $fallback_template_path);
	}

	/**
	* Set custom template location (able to use directory outside of phpBB).
	*
	* Note: Templates are still compiled to phpBB's cache directory.
	*
	* @param string $template_path Path to template directory
	* @param string $template_name Name of template
	* @param string $fallback_template_path Path to fallback template
	*/
	public function set_custom_template($template_path, $style_name, $fallback_template_path = false)
	{
		$this->locator->set_custom_template($template_path, $fallback_template_path);

		$this->cachepath = $this->phpbb_root_path . 'cache/tpl_' . str_replace('_', '-', $style_name) . '_';

		$this->context = new phpbb_template_context();

		return true;
	}

	/**
	* Converts a style name to relative (to board root) path to
	* the style's template files.
	*
	* @param $style_name string Style name
	* @return string Path to style template files
	*/
	private function relative_template_root_for_style($style_name)
	{
		return 'styles/' . $style_name . '/template';
	}

	/**
	* Sets the template filenames for handles.
	*
	* @param array $filname_array Should be a hash of handle => filename pairs.
	*/
	public function set_filenames(array $filename_array)
	{
		$this->locator->set_filenames($filename_array);

		return true;
	}

	/**
	* Clears all variables and blocks assigned to this template.
	*/
	public function destroy()
	{
		$this->context->clear();
	}

	/**
	* Reset/empty complete block
	*
	* @param string $blockname Name of block to destroy
	*/
	public function destroy_block_vars($blockname)
	{
		$this->context->destroy_block_vars($blockname);
	}

	/**
	* Display a template for provided handle.
	*
	* The template will be loaded and compiled, if necessary, first.
	*
	* This function calls hooks.
	*
	* @param string $handle Handle to display
	* @return bool True on success, false on failure
	*/
	public function display($handle)
	{
		$result = $this->call_hook($handle);
		if ($result !== false)
		{
			return $result[0];
		}

		return $this->load_and_render($handle);
	}

	/**
	* Loads a template for $handle, compiling it if necessary, and
	* renders the template.
	*
	* @param string $handle Template handle to render
	* @return bool True on success, false on failure
	*/
	private function load_and_render($handle)
	{
		$renderer = $this->_tpl_load($handle);

		if ($renderer)
		{
			$renderer->render($this->context, $this->get_lang());
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	* Calls hook if any is defined.
	*
	* @param string $handle Template handle being displayed.
	*/
	private function call_hook($handle)
	{
		global $phpbb_hook;

		if (!empty($phpbb_hook) && $phpbb_hook->call_hook(array(__CLASS__, __FUNCTION__), $handle, $this))
		{
			if ($phpbb_hook->hook_return(array(__CLASS__, __FUNCTION__)))
			{
				$result = $phpbb_hook->hook_return_result(array(__CLASS__, __FUNCTION__));
				return array($result);
			}
		}

		return false;
	}

	/**
	* Obtains language array.
	* This is either lang property of $user property, or if
	* it is not set an empty array.
	* @return array language entries
	*/
	public function get_lang()
	{
		if (isset($this->user->lang))
		{
			$lang = $this->user->lang;
		}
		else
		{
			$lang = array();
		}
		return $lang;
	}

	/**
	* Display the handle and assign the output to a template variable
	* or return the compiled result.
	*
	* @param string $handle Handle to operate on
	* @param string $template_var Template variable to assign compiled handle to
	* @param bool $return_content If true return compiled handle, otherwise assign to $template_var
	* @return bool|string false on failure, otherwise if $return_content is true return string of the compiled handle, otherwise return true
	*/
	public function assign_display($handle, $template_var = '', $return_content = true)
	{
		ob_start();
		$result = $this->display($handle);
		$contents = ob_get_clean();
		if ($result === false)
		{
			return false;
		}

		if ($return_content)
		{
			return $contents;
		}

		$this->assign_var($template_var, $contents);

		return true;
	}

	/**
	* Obtains a template renderer for a template identified by specified
	* handle. The template renderer can display the template later.
	*
	* Template source will first be compiled into php code.
	* If template cache is writable the compiled php code will be stored
	* on filesystem and template will not be subsequently recompiled.
	* If template cache is not writable template source will be recompiled
	* every time it is needed. DEBUG_EXTRA define and load_tplcompile
	* configuration setting may be used to force templates to be always
	* recompiled.
	*
	* Returns an object implementing phpbb_template_renderer, or null
	* if template loading or compilation failed. Call render() on the
	* renderer to display the template. This will result in template
	* contents sent to the output stream (unless, of course, output
	* buffering is in effect).
	*
	* @param string $handle Handle of the template to load
	* @return phpbb_template_renderer Template renderer object, or null on failure
	* @uses template_compile is used to compile template source
	*/
	private function _tpl_load($handle)
	{
		$output_file = $this->_compiled_file_for_handle($handle);

		$recompile = defined('DEBUG_EXTRA') ||
			!file_exists($output_file) ||
			@filesize($output_file) === 0;

		if ($recompile || $this->config['load_tplcompile'])
		{
			// Set only if a recompile or an mtime check are required.
			$source_file = $this->locator->get_source_file_for_handle($handle);

			if (!$recompile && @filemtime($output_file) < @filemtime($source_file))
			{
				$recompile = true;
			}
		}

		// Recompile page if the original template is newer, otherwise load the compiled version
		if (!$recompile)
		{
			return new phpbb_template_renderer_include($output_file, $this);
		}

		$compile = new phpbb_template_compile($this->config['tpl_allow_php']);

		if ($compile->compile_file_to_file($source_file, $output_file) !== false)
		{
			$renderer = new phpbb_template_renderer_include($output_file, $this);
		}
		else if (($code = $compile->compile_file($source_file)) !== false)
		{
			$renderer = new phpbb_template_renderer_eval($code, $this);
		}
		else
		{
			$renderer = null;
		}

		return $renderer;
	}

	/**
	* Determines compiled file path for handle $handle.
	*
	* @param string $handle Template handle (i.e. "friendly" template name)
	* @return string Compiled file path
	*/
	private function _compiled_file_for_handle($handle)
	{
		$source_file = $this->locator->get_filename_for_handle($handle);
		$compiled_file = $this->cachepath . str_replace('/', '.', $source_file) . '.' . $this->phpEx;
		return $compiled_file;
	}

	/**
	* Assign key variable pairs from an array
	*
	* @param array $vararray A hash of variable name => value pairs
	*/
	public function assign_vars(array $vararray)
	{
		foreach ($vararray as $key => $val)
		{
			$this->assign_var($key, $val);
		}
	}

	/**
	* Assign a single variable to a single key
	*
	* @param string $varname Variable name
	* @param string $varval Value to assign to variable
	*/
	public function assign_var($varname, $varval)
	{
		$this->context->assign_var($varname, $varval);
	}

	// Docstring is copied from phpbb_template_context method with the same name.
	/**
	* Assign key variable pairs from an array to a specified block
	* @param string $blockname Name of block to assign $vararray to
	* @param array $vararray A hash of variable name => value pairs
	*/
	public function assign_block_vars($blockname, array $vararray)
	{
		return $this->context->assign_block_vars($blockname, $vararray);
	}

	// Docstring is copied from phpbb_template_context method with the same name.
	/**
	* Change already assigned key variable pair (one-dimensional - single loop entry)
	*
	* An example of how to use this function:
	* {@example alter_block_array.php}
	*
	* @param	string	$blockname	the blockname, for example 'loop'
	* @param	array	$vararray	the var array to insert/add or merge
	* @param	mixed	$key		Key to search for
	*
	* array: KEY => VALUE [the key/value pair to search for within the loop to determine the correct position]
	*
	* int: Position [the position to change or insert at directly given]
	*
	* If key is false the position is set to 0
	* If key is true the position is set to the last entry
	*
	* @param	string	$mode		Mode to execute (valid modes are 'insert' and 'change')
	*
	*	If insert, the vararray is inserted at the given position (position counting from zero).
	*	If change, the current block gets merged with the vararray (resulting in new key/value pairs be added and existing keys be replaced by the new value).
	*
	* Since counting begins by zero, inserting at the last position will result in this array: array(vararray, last positioned array)
	* and inserting at position 1 will result in this array: array(first positioned array, vararray, following vars)
	*
	* @return bool false on error, true on success
	*/
	public function alter_block_array($blockname, array $vararray, $key = false, $mode = 'insert')
	{
		return $this->context->alter_block_array($blockname, $vararray, $key, $mode);
	}

	/**
	* Include a separate template.
	*
	* This function is marked public due to the way the template
	* implementation uses it. It is actually an implementation function
	* and should not be considered part of template class's public API.
	*
	* @param string $filename Template filename to include
	* @param bool $include True to include the file, false to just load it
	* @uses template_compile is used to compile uncached templates
	*/
	public function _tpl_include($filename, $include = true)
	{
		$this->locator->set_filenames(array($filename => $filename));

		if (!$this->load_and_render($filename))
		{
			// trigger_error cannot be used here, as the output already started
			echo 'template->_tpl_include(): Failed including ' . htmlspecialchars($handle) . "\n";
		}
	}

	/**
	* Include a PHP file.
	*
	* If a relative path is passed in $filename, it is considered to be
	* relative to board root ($phpbb_root_path). Absolute paths are
	* also allowed.
	*
	* This function is marked public due to the way the template
	* implementation uses it. It is actually an implementation function
	* and should not be considered part of template class's public API.
	*
	* @param string $filename Path to PHP file to include
	*/
	public function _php_include($filename)
	{
		if (phpbb_is_absolute($filename))
		{
			$file = $filename;
		}
		else
		{
			$file = $this->phpbb_root_path . $filename;
		}

		if (!file_exists($file))
		{
			// trigger_error cannot be used here, as the output already started
			echo 'template->_php_include(): File ' . htmlspecialchars($file) . " does not exist\n";
			return;
		}
		include($file);
	}
}
