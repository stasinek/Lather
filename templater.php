<?php
	/**
	 * Simple template engine class (use {$tag} tags in your templates).
	 * 
	 * @link http://www.broculos.net/ Broculos.net Programming Tutorials
	 * @author Nuno Freitas <nunofreitas@gmail.com>
	 * @version 1.0 + 0.2 mod http://github.com/stasinek/lather
	 * @modified by SSTSOFT.pl {$} instead of just [@] to make it more feel alike Latte, 
	 * @Added basic support for arrays:
	 * @For variable $array set('array',$array) each ocurence of $array will be replaced by next element of = $array->{$variable}; 
	 * @In future hope to change it to be more sophisticated,
	 * @Will add support for $array[indexes] and it's {$array}->{$values}
	 * @v0.1 addded support for <?php?> & <?include()?>
	 * @v0.2 tweaked <?include()?> to support relative path ../ ./ or simmilar to CSS @import url() -> include()
	 * @v0.3 Added function: "attatch" files to keys, extend "set", combine output using multiple sources using "add"
	 */
    function path_eval($path,$base = null) {
			if ($base===null) $base = getcwd();
			$path = str_replace('\\','/', $path); $base = str_replace('\\','/', $base);
			$path_array = array_filter(explode('/', $path),'strlen'); $base_array = array_filter(explode('/', $base),'strlen');
			foreach ($path_array as $part) {
				if ('.' == $part) continue;
				else if ('..' == $part) array_pop($base_array);
						else $base_array[] = $part;
				}
			if (DIRECTORY_SEPARATOR=='/') return '/'.implode(DIRECTORY_SEPARATOR,$base_array);
			else return implode(DIRECTORY_SEPARATOR,$base_array);
			} 
//------------------------------------------------------------------------------------------------

class Stack
{
	/**
	 * Stack items collection.
	 * @param mixed[]
	 */
	private $items;
	public function __construct()
	{
		$this->items = array();
	}
	/**
	 * Adds an element on the collection top.
	 * @param mixed $item
	 */
	public function push($item)
	{
		array_push($this->items, $item);
	}
	/**
	 * If stack is not empty, removes the element that was last added.
	 */
	public function pop()
	{
		if(!$this->is_empty())	return array_pop($this->items);
	}
	/**
	 * If stack is not empty, show the element that was last added.
	 */
	public function top()
	{
		if(!$this->is_empty()) return end($this->items);
	}
	/**
	 * Checks if stack is empty.
	 */
	public function is_empty()
	{
		return count($this->items) ? false : true;
	}
	/**
	 * Converts stack collection to string output.
	 */
	public function __to_string()
	{
		if($this->is_empty())
			$items_string = 'Stack is empty.';
		else
			$items_string = implode(', ', $this->items);
		$items_string .= PHP_EOL; 
		return $items_string;
	}
	function set_last($array, $value) {
	$index = count($array) -1;
	if ($index < 0) 
		return;
	$array[$index] = $value;
	}
	function get_last($array, $value) {
	$index = count($array) -1;
	if ($index < 0) 
		return;
	return $array[$index];
	}
}
//------------------------------------------------------------------------------------------------
    class Template {
    	/**
    	 * The filename of the template to load.
    	 *
    	 * @access protected
    	 * @var string
    	 */
        protected $file;
        /**
         * An array of values for replacing each tag on the template (the key for each value is its corresponding tag).
         *
         * @access protected
         * @var array
         */
		protected $values = array();
		protected $output = "";
		protected $opened = false;
		protected $locked = 0;
		public $debug = false;
        /**
         * Creates a new Template object and sets its associated file.
         *
         * @param string $file the filename of the template to load
         */
     public function __construct($file, $parent = null) {
       $this->file = $file;
			if ($parent!=null) $this->values = $parent->values;
			}
		public function __clone() {
			$current_lock = microtime();
			while ($this->locked != 0); // copy finite product, wait for changes (multiprocess enviroment)	
			$this->locked = $current_lock;
			while ($this->locked != $current_lock); // copy finite product, wait for changes (multiprocess enviroment)	
			$cloned = new Template($this->file);
			$cloned->values = $this->values;
			$cloned->output = $this->output;
			$this->locked = 0;
			return $cloned;
			}
		public function open() {
			$current_lock = microtime();
			while ($this->locked != 0); // copy finite product, wait for changes (multiprocess enviroment)	
			$this->locked = $current_lock;
			while ($this->locked != $current_lock); // copy finite product, wait for changes (multiprocess enviroment)	
			$this->output = file_get_contents($this->file);
			$this->locked = 0;
			if ($this->output===false) { $this->report_status("Prepare()"); return false; }
			else return $this->opened = true;
		}		
		public function attatch($file, $key = null) {
			$current_lock = microtime();
			while ($this->locked != 0); // copy finite product, wait for changes (multiprocess enviroment)	
			$this->locked = $current_lock;
			while ($this->locked != $current_lock); // copy finite product, wait for changes (multiprocess enviroment)	
			if (key===null) $this->output  .= file_get_contents($file); 
			else $contents = file_get_contents($file);
			$this->locked = false;
			$this->set($key, $contents);
			if ($this->output===false) { $this->report_status("Attatch()"); return false; }
			else return $this->opened = true;			
		}		
		// function able to replace defined tag and evaluate php
		function eval_scripts(&$output,$header = "php") {
            $header_len = strlen($header);
			// Eval PHP scripts NOTE: using replaced values by set! consider as server side "javascript" replacement.
			// Here could do loops evaluate more complicated variables, include files, 
			while (($posb = strpos($output,"<"."?".$header))!==false) 
				{
					$pose = strpos($output,"?".">",$posb);
				if ($pose!==false) {
					$posbcc = 2 + $header_len; $posecc = 2; $pose += 2;
					//remove heading spaces
					while ($output[$posb+$posbcc]==' ' OR $output[$posb+$posbcc]=='\n') $posbcc++;
					//remove tailing spaces
					while ($output[$pose-1]==' ' OR $output[$pose-1]=='\n') $posecc++;
					//extract string for evaluation
					$toeval = substr($output,$posb + $posbcc,$pose - $posb - $posecc - $posbcc);
					//save existing echo buffer for a while
					$buffered_len = ob_get_length();
					if ($buffered_len!==false ? $buffered_len > 0 : false) {
						$buffered = ob_get_clean();
						}
					//redirect PHP echo into separate buffer
					ob_start();
					//$old_error_handler = set_error_handler("$this->error_handler",E_ALL);
					//$result = @eval($evalcode . "; return true;");
try {
					eval($toeval);
 } catch (Exception $e) {
    $this->report_status('Template: "'.$this->file.'" '.$e->getMessage());
}
					//if ($old_error_handler!=null) set_error_handler($old_error_handler,E_ALL);
					//	else restore_error_handler();
					$evaluated = ob_get_clean();
					$output = substr_replace($output,$evaluated,$posb,$pose - $posb);
					//restore PHP parent echo buffer
					if ($buffered_len!==false ? $buffered_len > 0 : false) { 
						ob_start(); echo $buffered; 
						}
					} 
				}
			return @$output;
		}
		// replace each ocurence of keyword with value set by this->set(key,value);
		function replace_variables(&$output) {
			foreach ($this->values as $key => $value) 
				{
            	$tagToReplace[] = array("{"."$"."{$key}"."}","{"."@"."{$key}"."}","$"."{"."{$key}"."}","@"."{"."{$key}"."}");
            	if  (is_array($value)) 
					{
					for ($value_index = 0;($posb = strpos($output,$tagToReplace))!==false; $value_index++) 
						{
						if ($value->count() < $value_index) 
							break;
						$indexed_value = $value->{$value_index};
						for ($tags = count($tagToReplace,0) - 1; $tags >= 0; $tags--) {
							 $output = substr_replace($tagToReplace[$tags],$indexed_value,$output);
							}
						}
					}
				else 
					{ 
					for ($tags = count($tagToReplace,0) - 1; $tags >= 0; $tags--) {
						 $output = str_replace($tagToReplace[$tags], $value, $output);
						}
					}
				}
		return @$output;
		}
		// function that is able to replace defined tag for example include and import file cascade.
		// will work almost as CSS @import url() with small exception -> base path of template will be relative path for all files
		// just like http://host.com/dirname($this->file) for example ./subdir will be imported from dirname(this->file)/subdir
		function import_files(&$output,$header = "include",$require = false, $echo = false) {
            $header_len = strlen($header);
			while (($posb = strpos($output,"<"."?".$header))!==false) 
			{
					$pose = strpos($output,"?".">",$posb);
				if ($pose!==false) {
					$posbcc  = 2 + $header_len; $posecc  = 2; $pose += 2;
					// incluce(, include (, include <, include[, include(" and so on..
					//remove heading spaces
					while ($output[$posb+$posbcc+0]==' ') 
						$posbcc++;
					//remove tailing spaces
					while ($output[$pose-$posecc-1]==' ') 
						$posecc++;
					//remove heading < [ ( and respective tailing ) ] >
					while ($output[$posb+$posbcc]== '(' AND $output[$pose-$posecc-1]== ')')
						{ $posbcc++; $posecc++; }
					//remove heading spaces
					while ($output[$posb+$posbcc+0]==' ') 
						$posbcc++;
					//remove tailing spaces
					while ($output[$pose-$posecc-1]==' ') 
						$posecc++;
					while ($output[$posb+$posbcc]== '<' AND $output[$pose-$posecc-1]== '>')
						{ $posbcc++; $posecc++; }
					//remove heading spaces
					while ($output[$posb+$posbcc+0]==' ') 
						$posbcc++;
					//remove tailing spaces
					while ($output[$pose-$posecc-1]==' ') 
						$posecc++;
					while ($output[$posb+$posbcc]== '[' AND $output[$pose-$posecc-1]== ']')
						{ $posbcc++; $posecc++; }
					//remove heading spaces
					while ($output[$posb+$posbcc]==' ') 
						$posbcc++;
					//remove tailing spaces
					while ($output[$pose-$posecc-1]==' ') 
						$posecc++;
					//remove heading ",'
					while ($output[$posb+$posbcc]=="'" AND $output[$pose-$posecc-1]=="'") 
						{ $posbcc++; $posecc++; }
					//remove tailing ",'
					while ($output[$posb+$posbcc]=='"' AND $output[$pose-$posecc-1]=='"') 
						{ $posbcc++; $posecc++; }
							// check is path relative starting with '.' or '..' or '/' 
						$toinclude = substr($output,$posb + $posbcc,$pose - $posb - $posecc - $posbcc);
					if ($toinclude!= null ? ($toinclude[0]!='/' AND $toinclude[1]!=':') : false) { 
						$toinclude = path_eval($toinclude,dirname($this->file));
						}
					// FINALLY: open file and take contents
					if (file_exists($toinclude)) 
						{
						if ($echo==true) 
							{
							$echo_template = new Template($toinclude,$this);
							$included_content = $echo_template->output();
							}						
						else $included_content = file_get_contents($toinclude);
						}
					else 
						$included_content = false;
					// if cant get content's trow error. (optionally could skip & just continue NO_ERRORS option to Lather?)
					if ($included_content===false) {
						if ($require==false) $included_content = "";
						else 
							{
							$included_content  = 'Templater could not include file: "'.$toinclude.'" position '.$posb;
							$included_content .= ' in '.$this->format_status();
							$this->report_status($included_content);
							}
						}	// REPLACE TAG with file content
					$output = substr_replace($output,$included_content,$posb,$pose - $posb);
				}	
			}
		return @$output;
		}
        /**
         * Sets a value for replacing a specific tag.
         *
         * @param string $key the name of the tag to replace
         * @param string $value the value to replace
         */
     public function set($key, $value) {
			if ($this->debug) $this->report_status('Set(key="'.serialize($key).'", value="'.serialize($value).'")');
			$this->values[$key] = $value;
       }
     public function add($key, $value) {
				if ($this->debug) $this->report_status('Add(key="'.serialize($key).'", value="'.serialize($value).'")');
				if (isset($this->values[$key])) $this->values[$key] += $value;
				else $this->values[$key] = $value;
       }
     public function erase($key) {
				if ($this->debug) $this->report_status('Erase(key="'.serialize($key).'")');
				$this->values[$key] = "";
       }
        /**
         * Outputs the content of the template, replacing the keys for its respective values.
		 * Evaluates every PHP script between {?php ?}
         *
         * @return string
         */
        public function output() {
        	/**
        	 * Tries to verify if the file exists.
        	 * If it doesn't return with an error message.
        	 * Anything else loads the file contents and loops through the array replacing every key for its value.
        	 */
            if ($this->opened==false) if ($this->open()==false) return "";
			$this->locked = true;
			// Set, arrays or regular @{values} before inlcude happens
            // 0: VAR REPLACE
			$this->output = $this->replace_variables($this->output);
 			// Inlcude common files as nested common templates
			// 1: IMPORT COMMON MODULES			
			$this->output = $this->import_files($this->output,"require",true);
			$this->output = $this->import_files($this->output,"include");
			// Set, arrays or regular @{values} yet again inside each imported template
            // 2: VAR REPLACE
			$this->output = $this->replace_variables($this->output);
			// once included common files, replaced common variables, this block gives ability to evaluate php srcipts, 
			// for example loops, conditional code, pasted arrays etc, include something once again to output and process
			// whole tag will be replaced by anything it prints to the output. But doesnt need to make such.
			// It can be used to prepare additional files as well
			// 3: PHP EVAL
			$this->output = $this->eval_scripts($this->output,"php");
			// 4: IMPORT other template with separate context simmilar to 'print' or 'echo'
			$this->output = $this->import_files($this->output,"import");
            // 4: CLONE, UNLOCK locked output
			//$finite = $this->output; 
			$this->locked = false;
            // 5: FINITO, RETURN FINAL PRODUCT preprocessed by previous 3 steps.
			return $this->output;
        }
        /**
         * Alias for output();
         */
        public function content() { return $this->output();
			}
        /**
         * Prints the content.
         */
        public function print_content() { echo $this->output();
			}
        /**
         * Merges the content from an array of templates and separates it with $separator.
         *
         * @param array $templates an array of Template objects to merge
         * @param string $separator the string that is used between each Template object
         * @return string
         */
        static public function merge($templates, $separator = "\n") {
        	/**
        	 * Loops through the array concatenating the outputs from each template, separating with $separator.
        	 * If a type different from Template is found we provide an error message. 
        	 */
            $output = "";
            foreach ($templates as $template) {
            	$content = (get_class($template) !== "Template")
            		? 'Template->merge() Error, incorrect type - expected Template, invoked by '.debug_backtrace()[0]['function'].'() in file :"'.debug_backtrace()[0]['file'].'" at line: '.debug_backtrace()[0]['line'].' '
            		: $template->output();
//				trigger_error($content,E_USER_NOTICE);
            	$output .= $content . $separator;
            }
            return $output;
        }
		// prepare message with current caller context
	function format_status($operation = 'Unknown'){
			return 'Template: "'.$this->file.'" '.$operation.' invoked by '.debug_backtrace()[2]['function'].'() in file :"'.debug_backtrace()[1]['file'].'" at line: '.debug_backtrace()[1]['line'].'; ';
		}
		//
	function report_status($status){
			trigger_error($status,E_USER_NOTICE);
		}
	public function error_handler($errno,$errstr,$errfile,$errline)
	{ 
	switch ($errno) {
		case E_USER_NOTICE:
			$this->error_list[] = sprintf("%04d - ",count($this->error_list))
			.'<b>NOTICE</b> ['.$errno.'] '.$errstr
			.' on line '.$errline.' in file '.$this->file.'<br/>';
        break;
		case E_USER_WARNING:
			$this->error_list[] = sprintf("%04d - ",count($this->error_list))
			.'<b>WARNING</b>['.$errno.'] '.$errstr
			.' on line '.$errline.' in file '.$this->file.'<br/>';
        break;
		case E_USER_ERROR:
			$this->error_list[] = sprintf("%04d - ",count($this->error_list))
			.'<b>FATAL ERROR</b>  ['.$errno.'] '.$errstr
			.' on line '.$errline.' in file '.$this->file.', PHP '.PHP_VERSION().PHP_OS().'<br/>Aborting...<br/>';
			print_errors(); exit(1); break;
		default:
			$error_list[] = sprintf("%04d - ",count($error_list))
			.'<b>UNKNOWN ERROR</b> Type: ['.$errno.'] '.$errstr
			.' on line '.$errline.' in file '.$this->file.'<br/>';
       break;
    }
    /* Don't execute PHP internal error handler */
    return true;
}
//------------------------------------------------------------------------------------------------

	function print_errors()
	{
		echo ' <ul style="float:left; text-align:left;>';
		foreach ($this->error_list as $error)
		{ 
			echo '<li">'.$error.'</li>';
		}
		echo '</ul>';
		}
    }

?>