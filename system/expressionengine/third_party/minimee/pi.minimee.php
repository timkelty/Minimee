<?php
if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once PATH_THIRD . 'minimee/config.php';
require_once PATH_THIRD . 'minimee/models/Minimee_config.php';
require_once PATH_THIRD . 'minimee/models/Minimee_logger.php';

$plugin_info = array(
	'pi_name'			=> MINIMEE_NAME,
	'pi_version'		=> MINIMEE_VER,
	'pi_author'			=> MINIMEE_AUTHOR,
	'pi_author_url'		=> MINIMEE_DOCS,
	'pi_description'	=> MINIMEE_DESC,
	'pi_usage'			=> Minimee::usage()
);

/**
 * Minimee: minimize & combine your CSS and JS files. For EE2 only.
 * @author John D Wells <http://johndwells.com>
 * @license http://www.opensource.org/licenses/bsd-license.php BSD license
 * @link	http://johndwells.com/software/minimee
 */
class Minimee {

	/* usage settings */
	public $queue;

	public $filesdata			= array();
	public $stylesheet_query	= array();
	public $template;
	public $type;
	
	public $EE;
	public $ext;

	public $log;
	public $config;


	/**
	 * Constructor
	 *
	 * @return void
	 */
	public function __construct()
	{
		$this->EE =& get_instance();

		// create our logger
		$this->log = new Minimee_logger();

		// create our config
		$this->config = new Minimee_config();

		// be sure we have a cache set up
		if ( ! isset($this->EE->session->cache['minimee']))
		{
			$this->EE->session->cache['minimee'] = array();
		}
	}
	// END


	/**
	 * Plugin function: exp:minimee:css
	 * 
	 * @return mixed string or empty
	 */
	public function css()
	{
		$this->type = 'css';
		return $this->_run();
	}
	// END


	/**
	 * Plugin function: exp:minimee:display
	 * 
	 * @return mixed string or empty
	 */
	public function display()
	{
		// see which to display
		$js = strtolower($this->EE->TMPL->fetch_param('js'));
		$css = strtolower($this->EE->TMPL->fetch_param('css'));
		$out = '';

		if ($js)
		{
			$this->queue = $js;
			$this->type = 'js';
			$out .= $this->_display();
		}

		if ($css)
		{
			$this->queue = $css;
			$this->type = 'css';
			$out .= $this->_display();
		}
		
		// free memory where possible
		unset($js);
		unset($css);

		return $out;
	}
	// END
	
	
	/**
	 * Plugin function: exp:minimee:embed
	 * 
	 * @return mixed string or empty
	 */
	public function embed()
	{
		// make sure only one is being specified
		if ($this->EE->TMPL->fetch_param('js') && $this->EE->TMPL->fetch_param('css'))
		{
			return $this->_abort('Minimee has aborted: When using the embed method, you may not specify JS and CSS file types together.');
		}

		if ($this->EE->TMPL->fetch_param('js'))
		{
			$this->queue = $this->EE->TMPL->fetch_param('js');
			$this->type = 'js';
		}

		if ($this->EE->TMPL->fetch_param('css'))
		{
			$this->queue = $this->EE->TMPL->fetch_param('css');
			$this->type = 'css';
		}
		
		return $this->_embed();
	}
	// END


	/**
	 * Plugin function: exp:minimee:html
	 * 
	 * @return void
	 */
	public function html()
	{
		// we do not need to actually do anything. Simply being called is enough.
		return;
	}
	// END


	/**
	 * Plugin function: exp:minimee:js
	 * 
	 * @return mixed string or empty
	 */
	public function js()
	{
		$this->type = 'js';
		return $this->_run();
	}
	// END


	/**
	 * Abort and return original or reconstructed tagdata.
	 * Attempts to handle any exceptions thrown.
	 *
	 * @param mixed The caught exception or empty string
	 * @return string The un-Minimeed tagdata
	 */	
	private function _abort($e = FALSE)
	{
		if ($e)
		{
			$log = $e->getMessage();
		}
		else
		{
			$log = 'Aborted without a specific error.';
		}

		// log our error message
		$this->log->error($log);

		// Let's return the original tagdata, wherever it came from
		if ($this->queue && array_key_exists($this->queue, $this->EE->session->cache['minimee'][$this->type]))
		{
			return $this->EE->session->cache['minimee'][$this->type][$this->queue]['tagdata'];
		}
		else
		{
			return $this->EE->TMPL->tagdata;
		}
	}
	// END
	
	
	/** 
	 * Internal function for writing cache files
	 * [Adapted from CodeIgniter Carabiner library]
	 * 
	 * @param	String of filename of the new file
	 * @param	String of contents of the new file
	 * @return	boolean	Returns true on successful cache, false on failure
	 */
	private function _cache($filename, $file_data)
	{
		$filepath = $this->EE->functions->remove_double_slashes($this->config->cache_path . '/' . $filename);
		$success = file_put_contents($filepath, $file_data);
		
		if ($success === FALSE)
		{ 
			throw new Exception('There was an error writing cache file ' . $filename . ' to ' . $this->config->cache_path);
		}

		// borrowed from /system/expressionengine/libraries/Template.php
		// FILE_READ_MODE is set in /system/expressionengine/config/constants.php
		@chmod($filepath, FILE_READ_MODE);

		$this->log->info('Cache file `' . $filename . '` was written to ' . $this->config->cache_path);

		// free memory where possible
		unset($filepath);
		unset($success);
	}
	// END
	
	
	/**
	 * Find out more info about each file
	 * Attempts to get file modification times, determine what files exist, etc
	 * 
	 * @return bool TRUE if all are found; FALSE if at least one is not found
	 */
	private function _check_headers()
	{
		// query for any stylesheets	
		$stylesheet_versions = $this->_fetch_stylesheet_versions();

		// now, loop through our filesdata and set all headers	
		foreach ($this->filesdata as $key => $file) :

			/**
			 * Stylesheets (e.g. {stylesheet='template/file'}
			 */
			if ($file['type'] == 'stylesheet') {

				if ($stylesheet_versions && array_key_exists($file['stylesheet'], $stylesheet_versions))
				{
					// transform name out of super global and into valid URL
					$this->filesdata[$key]['name'] = $this->EE->functions->fetch_site_index().QUERY_MARKER.'css='.$file['stylesheet'].(($this->EE->config->item('send_headers') == 'y') && isset($stylesheet_versions[$file['stylesheet']]) ? '.v.'.$stylesheet_versions[$file['stylesheet']] : '');
					$this->filesdata[$key]['lastmodified'] = $stylesheet_versions[$file['stylesheet']];
				}
				else
				{
					throw new Exception('Missing file has been detected: ' . $file['stylesheet']);
				}
				
				// skip the rest of the current loop iteration
				continue;
			}

			/**
			 * Remote files
			 */
			if ($file['type'] == 'remote') {

				// try replacing url with base path, and see if there's a file there
				$alias = str_ireplace($this->config->base_url, $this->config->base_path, $file['name']);
				if (file_exists($alias))
				{
					// let's take a chance!
					$this->filesdata[$key]['name'] = str_ireplace($this->config->base_url, '', $file['name']);
					$this->filesdata[$key]['type'] = 'local';
					
					$this->log->info('Treating `' . $file['name'] . '` as a local file: `' . $this->filesdata[$key]['name'] . '`');
				}
				else
				{
					// skip the rest of the current loop iteration
					continue;
				}
			}
		
			/**
			 * Local files
			 */
			$realpath = realpath($this->EE->functions->remove_double_slashes($this->config->base_path . '/' . $this->filesdata[$key]['name']));
			if ( ! file_exists($realpath))
			{
				throw new Exception('Missing file has been detected: ' . $this->EE->functions->remove_double_slashes($this->config->base_path . '/' . $this->filesdata[$key]['name']));
			}

			$this->filesdata[$key]['lastmodified'] = filemtime($realpath);
			$this->filesdata[$key]['lastmodified'] = ($this->filesdata[$key]['lastmodified'] == 0) ? '0000000000' : $this->filesdata[$key]['lastmodified'];

		endforeach;

		// free memory where possible
		unset($realpath);
		unset($stylesheet_versions);
	}
	// END


	/**
	 * Processes and displays queue
	 *
	 * @return string The final output from Minimee::out()
	 */	
	private function _display()
	{
		try
		{
			$this->_fetch_params();
			$this->_fetch_queue();
			$this->_flightcheck();
			$this->_check_headers();
			
			return $this->_out();
		}
		catch (Exception $e)
		{
			return $this->_abort($e);
		}
	}
	// END


	/**
	 * Processes things like normal, but at the last minute
	 * we grab the contents of the cached file, and return directly to our template.
	 * 
	 * @return mixed string or empty
	 */
	private function _embed()
	{
		try
		{
			$this->_fetch_params();
			$this->_fetch_queue();
			$this->_flightcheck();
			$this->_check_headers();
			
			// this is what we'd normally return to a template
			$out = $this->_out();

			// let's find the location of our cache files
			switch (strtolower($this->type)) :

				case 'css' :
					$pat = "/<link{1}.*?href=['|\"']{1}(.*?)['|\"]{1}[^>]*>/i";
				break;

				case 'js' :
					$pat = "/<script{1}.*?src=['|\"]{1}(.*?)['|\"]{1}[^>]*>(.*?)<\/script>/i";
				break;

				default :
					throw new Exception('No appropriate css/js tags found to parse.');
				break;

			endswitch;

			if ( ! preg_match_all($pat, $out, $matches, PREG_PATTERN_ORDER))
			{
				throw new Exception('No files found to process.');
			}
			
			// replace the url with path
			$paths = str_replace($this->config->cache_url, $this->config->cache_path, $matches[1]);

			// clear $out so we can replace with code to embed
			$out = '';

			// fetch contents of each file
			foreach ($paths as $path)
			{
				// there's no way this doesn't exist... right?
				$out .= @file_get_contents($path) . "\n";
			}

			// free memory where possible
			unset($pat);
			unset($haystack);
			unset($matches);
			unset($paths);

			return $out;
		}
		catch (Exception $e)
		{
			return $this->_abort($e);
		}
	}
	// END
	

	/**
	 * Retrieve files from cache
	 *
	 * @return void
	 */	
	private function _fetch_queue()
	{
		if ( ! isset($this->EE->session->cache['minimee'][$this->type][$this->queue]))
		{
			throw new Exception('Could not find a queue of files by the name of \'' . $this->queue . '\'.');
		}

		// clear queue just in case
		$this->filesdata = array();

		$this->template = $this->EE->session->cache['minimee'][$this->type][$this->queue]['template'];

		// set our Minimee::filesdata array
		$this->_set_filesdata($this->EE->session->cache['minimee'][$this->type][$this->queue]['files']);

		// No files found?
		if ( ! is_array($this->filesdata) OR count($this->filesdata) == 0)
		{
			throw new Exception('No files found in the queue named \'' . $this->type . '\'.');
		}
	}
	// END
	

	/**
	 * Parse tagdata for <link> and <script> tags,
	 * pulling out href & src attributes respectively.
	 * [Adapted from SL Combinator]
	 * 
	 * @param string tagdata
	 * @param string either css or js
	 * @return bool TRUE on success of fetching files; FALSE on failure
	 */
	private function _fetch_files($haystack)
	{
		// first up, let's substitute stylesheet= for minimee=, because we handle these special
		$haystack = preg_replace("/".LD."\s*stylesheet=[\042\047]?(.*?)[\042\047]?".RD."/", '[minimee=$1]', $haystack);

		// parse globals if we find any EE syntax tags
		if (preg_match("/".LD."(.*?)".RD."/", $haystack) === 1)
		{
			$haystack = $this->EE->TMPL->parse_globals($haystack);
		}
	
		// choose our preg pattern based on type
		switch (strtolower($this->type)) :

			case 'css' :
				$pat = "/<link{1}.*?href=['|\"']{1}(.*?)['|\"]{1}[^>]*>/i";
			break;
				
			case 'js' :
				$pat = "/<script{1}.*?src=['|\"]{1}(.*?)['|\"]{1}[^>]*>(.*?)<\/script>/i";
			break;
				
			default :
				throw new Exception('No appropriate css/js tags found to parse.');
			break;

		endswitch;
		
		if ( ! preg_match_all($pat, $haystack, $matches, PREG_PATTERN_ORDER))
		{
			throw new Exception('No files found to process.');
		}

		// set our tag template
		$this->template = str_replace($matches[1][0], '{minimee}', $matches[0][0]);
		
		// set our files & filesdata arrays
		$this->_set_filesdata($matches[1]);

		// free memory where possible
		unset($haystack);
		unset($pat);
		unset($matches);
	}
	// END

	
	/**
	 * Fetch parameters from $this->EE->TMPL
	 * 
	 * @return void
	 */
	private function _fetch_params()
	{
		// pass all params through our config, will magically pick up what's needed
		$this->config->settings = $this->EE->TMPL->tagparams;

		// fetch queue if it hasn't already been set via Minimee::_display()
		if ( ! $this->queue)
		{
			$this->queue = strtolower($this->EE->TMPL->fetch_param('queue', NULL));
		}
	}
	// END


	/**
	 * Query DB for any stylesheets
	 * Borrowed from $EE->TMPL->parse_globals(): ./system/expressionengine/libraries/Template.php
	 *
	 * @return mixed array or FALSE
	 */
	private function _fetch_stylesheet_versions() {
	
		// nothing to do if Minimee::stylesheet_query is empty
		if ( ! $this->stylesheet_query) return FALSE;

		$versions = array();
		
		$sql = "SELECT t.template_name, tg.group_name, t.edit_date, t.save_template_file FROM exp_templates t, exp_template_groups tg
				WHERE  t.group_id = tg.group_id
				AND    t.template_type = 'css'
				AND    t.site_id = '".$this->EE->db->escape_str($this->EE->config->item('site_id'))."'";
	
		$css_query = $this->EE->db->query($sql.' AND ('.implode(' OR ', $this->stylesheet_query) .')');
		
		if ($css_query->num_rows() > 0)
		{
			foreach ($css_query->result_array() as $row)
			{
				$versions[$row['group_name'].'/'.$row['template_name']] = $row['edit_date'];

				if ($this->EE->config->item('save_tmpl_files') == 'y' AND $this->EE->config->item('tmpl_file_basepath') != '' AND $row['save_template_file'] == 'y')
				{
					$basepath = $this->EE->config->slash_item('tmpl_file_basepath').$this->EE->config->item('site_short_name').'/';
					$basepath .= $row['group_name'].'.group/'.$row['template_name'].'.css';
					
					if (is_file($basepath))
					{
						$versions[$row['group_name'].'/'.$row['template_name']] = filemtime($basepath);
					}
				}
			}
		}

		// free memory where possible
		unset($sql);
		unset($css_query);
		
		// return FALSE if none found
		return ($versions) ? $versions : FALSE;
	}
	// END


	/**
	 * Flightcheck - make some basic config checks before proceeding
	 *
	 * @return void
	 */
	private function _flightcheck()
	{
		/**
		 * If our cache path appears relative, append it to our base path
		 */
		if (strpos($this->config->cache_path, '/') !== 0)
		{
			$this->config->cache_path = $this->config->base_path . '/' . $this->config->cache_path;
		}

		/**
		 * If our cache url appears relative, append it to our base url
		 */
		if (strpos($this->config->cache_url, '//') !== 0 && ! preg_match("#https?://#", $this->config->cache_url))
		{
			$this->config->cache_url = $this->config->base_url . '/' . $this->config->cache_url;
		}

		/**
		 * Determine our runtime remote_mode setting
		 */
		$this->_set_remote_mode();

	
		// Flightcheck: determine if we can continue or disable permanently
		switch ('flightcheck') :

			case ($this->config->disable == 'yes') :
				throw new Exception('Disabled manually.');
			break;

			case ( $this->config->minify == 'no' && $this->config->combine == 'no') :
				throw new Exception('Disabled because both minify and combine are set to \'no\'.');
			break;

			case ( ! file_exists($this->config->cache_path)) :
			case ( ! is_writable($this->config->cache_path)) :
				throw new Exception('Not configured correctly: your cache folder `' . $this->config->cache_path . '` does not exist or is not writable.');
			break;

			default :
				$this->log->info('Passed flightcheck.');
			break;

		endswitch;
	}
	// END


	/** 
	 * Internal function for minifying assets
	 * [Adapted from CodeIgniter Carabiner library]
	 * 
	 * @param	Contents to be minified
	 * @param	mixed A relative path to use, if provided
	 * @return	String minified contents of file
	 */
	private function _minify($contents, $rel = FALSE)
	{
		switch ($this->type) :
			
			case 'js':
				require_once('libraries/jsmin.php');
				$this->jsmin = new jsmin();
				return $this->jsmin->minify($contents);
			break;
			
			case 'css':
				require_once('libraries/cssmin.php');
				$this->cssmin = new cssmin();
				
				// set a relative path if exists
				$relativePath = ($rel !== FALSE && $this->config->yes('css_relative_path')) ? $rel . '/' : NULL;
				
				// run and return
				return $this->cssmin->minify($contents, FALSE, $relativePath);
			break;

		endswitch;
	}
	// ------------------------------------------------------
	
	
	/**
	 * Performs heavy lifting of plugin, processing output and returning final tags
	 * [Adapted from CodeIgniter Carabiner library]
	 * 
	 * @return string The final tag to be returned to template
	 */	
	private function _out()
	{
		// our return variable	
		$out = '';

		// if we are not combining, then minify each file in turn
		if ($this->config->no('combine')) :

			$tags = array();

			foreach ($this->filesdata as $key => $file) :
			
				$this->filesdata[$key]['cache_filename'] = $file['lastmodified'] . md5($file['name']) . '.' . $this->type;

				if (file_exists($this->EE->functions->remove_double_slashes($this->config->cache_path . '/' . $this->filesdata[$key]['cache_filename'])))
				{
					$this->log->info('Returning a cached file: ' . $this->EE->functions->remove_double_slashes($this->config->cache_path . '/' . $this->filesdata[$key]['cache_filename']));
					$tags[$key] = $this->_tag($this->filesdata[$key]['cache_filename']);
				}
				else
				{
					// must get contents of file to minify
					switch ($file['type']) :
			
						case ('stylesheet');
						case ('remote') :

							switch ($this->config->remote_mode)
							{
								case ('fgc') :
									// I hate to suppress errors, but it's only way to avoid one from a 404 response
									$response = @file_get_contents($file['name']);
									if ($response && isset($http_response_header) && (substr($http_response_header[0], 9, 3) < 400))
									{
										$this->_cache($this->filesdata[$key]['cache_filename'], $this->_minify($response));
										$tags[$key] = $this->_tag($this->filesdata[$key]['cache_filename']);
									}
									else
									{
										throw new Exception('A problem occurred while fetching the following over file_get_contents(): ' . $file['name']);
									}
								break;
								
								case ('curl') :
									if ( ! isset($epicurl))
									{
										require_once(PATH_THIRD . 'minimee/libraries/EpiCurl.php');
										$epicurl = EpiCurl::getInstance();
									}

									$ch = FALSE;
									$ch = curl_init($file['name']);
									curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
									@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
									$curls[$key] = $epicurl->addCurl($ch);

									if ($curls[$key]->code >= 400)
									{
										throw new Exception('Error encountered while fetching \'' . $this->filesdata[$key]['name'] . '\' over cURL.');
									}
			
									$this->_cache($this->filesdata[$key]['cache_filename'], $this->_minify($curls[$key]->data));
									$tags[$key] = $this->_tag($this->filesdata[$key]['cache_filename']);
								break;

								default :
									throw new Exception('Could not fetch file \'' . $file['name'] . '\' because neither cURL or file_get_contents() appears available.');
								break;
							}

						break;
						
						case ('local') :
						default :
							$rel = dirname($this->config->base_url . $this->EE->functions->remove_double_slashes('/' . $file['name'] . '/'));
							$contents = file_get_contents(realpath($this->EE->functions->remove_double_slashes($this->config->base_path . '/' . $file['name']))) . "\n";
							
							$this->_cache($this->filesdata[$key]['cache_filename'], $this->_minify($contents, $rel));
							$tags[$key] = $this->_tag($this->filesdata[$key]['cache_filename']);
						break;
			
					endswitch;
				}

			endforeach;

			$out = implode('', $tags);
			
			// free memory where possible
			unset($tags);

		// combine (& possibly minify) files
		else :
		
			$lastmodified = 0;
			$cache_name = '';

			foreach ($this->filesdata as $key => $file)
			{
				$lastmodified = max($lastmodified, $file['lastmodified'] );
				$cache_name .= $file['name'];
			}

			$lastmodified = ($lastmodified == 0) ? '0000000000' : $lastmodified;
			$filename = $lastmodified . md5($cache_name) . '.' . $this->type;
	
			if (file_exists($this->EE->functions->remove_double_slashes($this->config->cache_path . '/' . $filename)))
			{
				$this->log->info('Returning a cached file: ' . $this->EE->functions->remove_double_slashes($this->config->cache_path . '/' . $filename));
				$out = $this->_tag($filename);
			}
			else
			{
				$contents = array();
				$relPaths = array();

				foreach ($this->filesdata as $key => $file) :
					switch ($file['type']) :
			
						case ('stylesheet');
						case ('remote') :
						
							switch ($this->config->remote_mode)
							{
								case ('fgc') :
									// I hate to suppress errors, but it's only way to avoid one from a 404 response
									$response = @file_get_contents($file['name']);
									if ($response && isset($http_response_header) && (substr($http_response_header[0], 9, 3) < 400))
									{
										$contents[$key] = $response;
										$relPaths[$key] = FALSE;
									}
									else
									{
										throw new Exception('A problem occurred while fetching the following over file_get_contents(): ' . $file['name']);
									}
								break;
								
								case ('curl') :

									if ( ! isset($epicurl))
									{
										require_once(PATH_THIRD . 'minimee/libraries/EpiCurl.php');
										$epicurl = EpiCurl::getInstance();
									}

									$ch = FALSE;
									$ch = curl_init($file['name']);
									curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
									@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
									$curls[$key] = $epicurl->addCurl($ch);

									if ($curls[$key]->code >= 400)
									{
										throw new Exception('Error encountered while fetching \'' . $this->filesdata[$key]['name'] . '\' over cURL.');
									}
			
									$contents[$key] = $curls[$key]->data;
									$relPaths[$key] = FALSE;
								break;
								
								default :
									throw new Exception('Could not fetch file \'' . $file['name'] . '\' because neither cURL or file_get_contents() appears available.');
								break;
							}


						break;
						
						case ('local') :
						default :
							$contents[$key] = file_get_contents(realpath($this->EE->functions->remove_double_slashes($this->config->base_path . '/' . $file['name']))) . "\n";
							$relPaths[$key] = dirname($this->config->base_url . $this->EE->functions->remove_double_slashes('/' . $file['name'] . '/'));
						break;
			
					endswitch;
				endforeach;
	
				// gotta see if things need minifying
				if ($this->config->yes('minify'))
				{
					$cache = '';
					foreach ($contents as $key => $content)
					{
						$cache .= $this->_minify($content, $relPaths[$key]);
					}

					$this->_cache($filename, $cache);

				}
				else
				{
					$this->_cache($filename, implode('', $contents));
				}

				// free memory where possible
				unset($contents);
				unset($relPaths);
				
				$out = $this->_tag($filename);
			}

		endif;

		// free memory where possible
		unset($lastmodified);
		unset($cache_name);
		unset($filename);
		
		return $out;
	}
	//END
	
	
	/**
	 * Called by Minimee:css and Minimee:js, performs basic run command
	 * 
	 * @return mixed string or empty
	 */
	private function _run()
	{
		try
		{
			$this->_fetch_params();
			$this->_fetch_files($this->EE->TMPL->tagdata);
	
			// Are we queueing for later? If so, just save in session
			if ($this->queue)
			{
				return $this->_set_queue();
			}
	
			$this->_flightcheck();
			$this->_check_headers();

			return $this->_out();
		}
		catch (Exception $e)
		{
			return $this->_abort($e);
		}
	}
	// END


	/**
	 * Set up our Minimee::filesdata arrays to prepare for processing
	 * 
	 * @param array array of files
	 * @return void
	 */
	private function _set_filesdata($files) {
	
		$dups = array();
	
		foreach ($files as $key => $file)
		{
			// try to avoid duplicates
			if (in_array($file, $dups)) continue;
		
			$dups[] = $file;
		
			$this->filesdata[$key] = array(
				'name' => $file,
				'type' => NULL,
				'cache_filename' => '',
				'lastmodified' => '0000000000',
				'stylesheet' => NULL
			);

			// from old _isURL() file from Carabiner Asset Management Library
			if (preg_match('@(https?://([-\w\.]+)+(:\d+)?(/([\w/_\.]*(\?\S+)?)?)?)@', $this->filesdata[$key]['name']) > 0)
			{
				$this->filesdata[$key]['type'] = 'remote';
			}
			elseif (strpos($this->filesdata[$key]['name'], 'minimee=') !== FALSE && preg_match("/\[minimee=[\042\047]?(.*?)[\042\047]?\]/", $this->filesdata[$key]['name'], $matches))
			{
				$this->filesdata[$key]['type'] = 'stylesheet';
				$this->filesdata[$key]['stylesheet'] = $matches[1];
	
				// prepare part of our SQL query for later			
				$ex = explode('/', $matches[1], 2);
				if (isset($ex[1]))
				{
					$this->stylesheet_query[] = "(t.template_name = '".$this->EE->db->escape_str($ex[1])."' AND tg.group_name = '".$this->EE->db->escape_str($ex[0])."')";
				}
			}
			else
			{
				$this->filesdata[$key]['type'] = 'local';
			}
	
		}
		
		// free memory where possible
		unset($dups);
	}
	// END
	

	/** 
	 * Adds the files to be queued into session
	 * 
	 * @param string either 'js' or 'css'
	 * @return void
	 */
	private function _set_queue()
	{
		// be sure we have a cache set up
		if ( ! isset($this->EE->session->cache['minimee'][$this->type]))
		{
			$this->EE->session->cache['minimee'][$this->type] = array();
		}

		// create new session array for this queue
		if ( ! array_key_exists($this->queue, $this->EE->session->cache['minimee'][$this->type]))
		{
			$this->EE->session->cache['minimee'][$this->type][$this->queue] = array(
				'template' => $this->template,
				'tagdata' => '',
				'files' => array()
			);
		}
		
		// Append tagdata - used if diplay() is disabled
		$this->EE->session->cache['minimee'][$this->type][$this->queue]['tagdata'] .= $this->EE->TMPL->tagdata;
		
		// Add all files to the queue cache
		foreach ($this->filesdata as $file)
		{
			if ( ! in_array($file['name'], $this->EE->session->cache['minimee'][$this->type][$this->queue]['files']))
			{
				$this->EE->session->cache['minimee'][$this->type][$this->queue]['files'][] = $file['name'];
			}
		}
	}
	// END
	

	/** 
	 * Determine our remote mode for this call
	 * 
	 * @param string either 'js' or 'css'
	 * @return void
	 */
	public function _set_remote_mode()
	{
		// if 'auto', then we try curl first
		if (preg_match('/auto|curl/i', $this->config->remote_mode) && in_array('curl', get_loaded_extensions()))
		{
			$this->log->info('Using CURL for remote files.');

			$this->config->remote_mode = 'curl';
			
			return;
		}

		// file_get_contents() is auto mode fallback
		if (preg_match('/auto|fgc/i', $this->config->remote_mode) && ini_get('allow_url_fopen'))
		{
			$this->log->info('Using file_get_contents() for remote files.');

			if ( ! defined('OPENSSL_VERSION_NUMBER'))
			{
				$this->log->debug('Your PHP compile does not appear to support file_get_contents() over SSL.');
			}

			$this->config->remote_mode = 'fgc';

			return;
		}
		
		// if we're here, then we cannot fetch remote files
		$this->log->debug('Remote files cannot be fetched.', 2);
	}


	/** 
	 * Internal function for making tag strings
	 * [Adapted from CodeIgniter Carabiner library]
	 * 
	 * @param	String	Filename
	 * @param	Boolean Whether the tag is for a cached file or not (default TRUE)
	 * @return	String containing an HTML tag reference to given reference
	 */
	private function _tag($filename, $cache = TRUE)
	{
		// only prepend cache_url if needed
		$url = ($cache) ? $this->config->cache_url . $this->EE->functions->remove_double_slashes('/' . $filename) : $filename;

		return str_replace('{minimee}', $url, $this->template);
	}
	// END


	/**
	 * Display usage notes in EE control panel
	 *
	 * @return string Usage notes
	 */	
	public function usage()
	{
		return <<<EOT
		
Complete and up-to-date documentation: http://johndwells.com/software/minimee

=====================================================
Basic Usage
=====================================================

CSS:
{exp:minimee:css}
	<link type="text/css" rel="stylesheet" href="/css/reset.css" />
	<link type="text/css" rel="stylesheet" href="/css/fonts.css" />
	<link type="text/css" rel="stylesheet" href="/css/screen.css" />
{/exp:minimee:css}

JS:
{exp:minimee:js}
	<script type="text/javascript" src="scripts/jquery.form.js"></script>
	<script type="text/javascript" src="scripts/jquery.easing.1.3.js"></script>
{/exp:minimee:js}


=====================================================
Tags
=====================================================

exp:minimee:css
- Compress & combine CSS files

exp:minimee:js
- Compress & combine JS files

exp:minimee:display
- Display files that have been queued for later
- See online docs for more information on usage

=====================================================
Parameters
=====================================================

disable="no"
- set to "yes" if you wish to temporarily disable this usage of plugin
- note that if Minimee is globally disabled via config, it cannot be overridden by this parameter

minify="yes"
- tells the plugin whether to minify files when caching; if set to 'no',
  it will not run files through minify engine

combine="yes"
- tells plugin whether to combine files when caching; if set to 'no',
  it will cache each file separately

queue="name"
- Allows you to create a queue of assets to be output at a later stage in code.
- The string passed to the queue parameter can later be used in the
  exp:minimee:display single tags. See online docs for more info and examples.

* Note: if minify & combine are both set to false, it performs no caching,
  effectively disabling plugin

	
EOT;

	}
}
	
/* End of file pi.minimee.php */ 
/* Location: ./system/expressionengine/third_party/minimee/pi.minimee.php */