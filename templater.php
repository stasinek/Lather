<?php
	/**
	 * Simple template engine class (use {$tag} tags in your templates).
	 * 
	 * @link http://www.broculos.net/ Broculos.net Programming Tutorials
	 * @author Nuno Freitas <nunofreitas@gmail.com>
	 * @version 1.0 + 0.2 mod http://github.com/stasinek/lather
	 * @modified by SSTSOFT.pl {$} instead of [@] more compatible with Latte, 
	 * @added basic support for arrays:
	 * @for variable $array set('array',$array) each ocurence of $array will be replaced by next element of = $array->{$variable}; 
	 * @in future hope to change it to be more sophisticated,
	 * @will add support for $array[indexes] and it's {$array}->{$values}
	 * @v0.1 addded support for <?php?> & <?include()?>
	 * @v0.2 tweaked <?include()?> to support relative path ../ ./ or simmilar to CSS @import url() -> include()
	 */
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
        /**
         * Creates a new Template object and sets its associated file.
         *
         * @param string $file the filename of the template to load
         */
        public function __construct($file) {
            $this->file = $file;
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
					while ($output[$posb+$posbcc]==' ') $posbcc++;
					//remove tailing spaces
					while ($output[$pose-1]==' ') $posecc++;
					//extract string for evaluation
					$toeval = substr($output,$posb + $posbcc,$pose - $posb - $posecc - $posbcc);
					//save existing echo buffer for a while
					$buffered_len = ob_get_length();
					if ($buffered_len!==false ? $buffered_len > 0 : false) {
						$buffered = ob_get_clean();
						}
					//redirect PHP echo into separate buffer
					ob_start();
					eval($toeval);
					$evaluated = ob_get_clean();
					$output = substr_replace($output,$evaluated,$posb,$pose - $posb);
					//restore PHP parent echo buffer
					if ($buffered_len!==false ? $buffered_len > 0 : false) { 
						ob_start(); echo $buffered; 
						}
					} 
				}
			return $output;
		}
		// replace each ocurence of keyword with value set by this->set(key,value);
		function replace_variables(&$output) {
			foreach ($this->values as $key => $value) 
				{
            	$tagToReplace[] = array("$"."{"."{$key}"."}","@"."{"."{$key}"."}");
            	if  (is_array($value)) 
					{
					for ($value_index = 0;($posb = strpos($output,$tagToReplace))!==false; $value_index++) 
						{
						if ($value->count() < $value_index) 
							break;
						$indexed_value = $value->{$value_index};
						for ($tags = 0; $tags < count($tagToReplace,0); $tags++) {
							 $output = substr_replace($tagToReplace[$tags],$indexed_value,$output);
							}
						}
					}
				else 
					{ 
					for ($tags = 0; $tags < count($tagToReplace,0); $tags++) {
						 $output = str_replace($tagToReplace[$tags], $value, $output);
						}
					}
				}
		return $output;
		}
		// function that is able to replace defined tag for example include and import file cascade.
		// will work almost as CSS @import url() with small exception -> base path of template will be relative path for all files
		// just like http://host.com/dirname($this->file) for example ./subdir will be imported from dirname(this->file)/subdir
		function import_files(&$output,$header = "include") {
            $header_len = strlen($header);
			while (($posb = strpos($output,"<"."?".$header))!==false) 
			{
					$pose = strpos($output,"?".">",$posb);
				if ($pose!==false) {
					$posbcc  = 2 + $header_len; $posecc  = 2; $pose += 2;
					// incluce(, include (, include <, include[, include(" and so on..
					//remove heading spaces
					while ($output[$posb+$posbcc]==' ') $posbcc++;
					//remove heading < [ (
					if ($output[$posb+$posbcc]== '(' || $output[$posb+$posbcc]== '[' || $output[$posb+$posbcc]=='<') 
						{ $posbcc++; }
					//remove heading spaces
					while ($output[$posb+$posbcc]==' ') $posbcc++;
					//remove heading ",'
					if ($output[$posb+$posbcc]=='\'' || $output[$posb+$posbcc]=='\"') 
						{ $posbcc++; }
					//remove heading spaces again
					while ($output[$posb+$posbcc]==' ') $posbcc++;
					//And the same thing at tail remove: spaces, ",',>,],) and so on..
					//remove tailing spaces
					while ($output[$pose-$posecc-1]==' ') $posecc++;
					//remove tailing ",'
					if ($output[$pose-$posecc-1]=='\'' || $output[$pose-$posecc-1]=='\"') $posecc++;
					//remove tailing > ] )
					if ($output[$pose-$posecc-1]== ')' || $output[$pose-$posecc-1]== ']' || $output[$pose-$posecc-1]=='>') $posecc++;
					//remove tailing spaces again
					while ($output[$pose-$posecc-1]==' ') $posecc++;
					// check is path relative starting with '.' or '..' or '/' 
						$toinclude = substr($output,$posb + $posbcc,$pose - $posb - $posecc - $posbcc);
					if ($toinclude!= null ? ($toinclude[0]!='/' AND $toinclude[1]!=':') : false) { 
						$toinclude = dirname($this->file).'/'.$toinclude; 
						}
					// FINALLY: open file and take contents
					$included_content = file_get_contents($toinclude);
					// if cant get content's trow error. (optionally could skip & just continue NO_ERRORS option to Lather?)
					if ($included_content===false) {
						$included_content = 'Templater could not include file: "'.$toinclude.'" position '.$posb.' in "'.$this->file.'" called by '.debug_backtrace()[1]['function'].'() in file :"'.debug_backtrace()[1]['file'].'" at line: '.debug_backtrace()[1]['line'];
						trigger_error($included_content,E_USER_NOTICE);
						}	// REPLACE TAG with file content
					$output = substr_replace($output,$included_content,$posb,$pose - $posb);
				}	
			}
		return $output;
		}
        /**
         * Sets a value for replacing a specific tag.
         *
         * @param string $key the name of the tag to replace
         * @param string $value the value to replace
         */
        public function set($key, $value) {
            $this->values[$key] = $value;
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
            if (!file_exists($this->file)) {
				$msg = 'Error loading template file ('.$this->file.') invoked by '.debug_backtrace()[0]['function'].'() in file :"'.debug_backtrace()[0]['file'].'" at line: '.debug_backtrace()[0]['line'].' ';
				trigger_error($msg,E_USER_NOTICE);
            	return $msg.'<br>';
            }
            $output = file_get_contents($this->file);
 			// inlcude common files as nested by custom template
			// 1: IMPORT COMMON MODULES
			$output = $this->import_files($output,"import");
			// Set, arrays or regular $values as paired before by set function
            // 2: VAR REPLACE
			$output = $this->replace_variables($output);
			// once included common files, replaced common variables, this block gives ability to evaluate php srcipts, 
			// for example loops, conditional code, pasted arrays etc, include something once again to output
			// 3: PHP EVAL
			$output = $this->eval_scripts($output,"php");
            // 4: FINITO, RETURN FINAL PRODUCT preprocessed by previous 3 steps.
			return $output;
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
    }

?>