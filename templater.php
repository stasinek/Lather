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
 			// inlcude file as nested template
			while (($posb = strpos($output,"<"."?include"))!==false) 
			{
					$pose = strpos($output,"?".">",$posb);
				if ($pose!==false) {
					$posbcc = 10; $posecc = 3;
					// incluce(, include (, include <, include[, include(" and so on..
					//remove heading spaces
					while ($output[$posb+$posbcc]==' ') $posbcc++;
					//remove heading > ] )
					if ($output[$posb+$posbcc]== '(' || $output[$posb+$posbcc]== '[' || $output[$posb+$posbcc]=='<') 
						{ $posbcc++; }
					while ($output[$posb+$posbcc]==' ') $posbcc++;
					//remove heading ",'
					if ($output[$posb+$posbcc]=='\'' || $output[$posb+$posbcc]=='\"') 
						{ $posbcc++; }
					while ($output[$posb+$posbcc]==' ') $posbcc++;
					// incluce -> >,],),"),') and so on..
					//remove tailing spaces
					while ($output[$pose-1]==' ') {$posecc++;$pose--;}
					//remove tailing > ] )
					if ($output[$pose-1]== ')' || $output[$pose-1]== ']' || $output[$pose-1]=='>') 
						{ $posecc++; $pose--; }
					while ($output[$pose-1]==' ') {$posecc++;$pose--;}
					//remove tailing ",'
					if ($output[$pose-1]=='\'' || $output[$pose-1]=='\"') 
						{ $posecc++; $pose--; }
					while ($output[$pose-1]==' ') {$posecc++;$pose--;}
					// check is path relative starting with '.' or '..' or '/' 
						$toinclude = substr($output,$posb + $posbcc,$pose - ($posb + $posbcc));
					if [$toinclude!= null ? $toinclude[0]!='/' AND $toinclude[1]!=':' : false) { 
						dirname($this->file).'/'.$toinclude; 
						}
					// FINALLY: open file and take contents
					$included_content = file_get_contents($toinclude);
					// if cant get content's trow error. (optionally could skip & just continue NO_ERRORS option to Lather?)
					if ($included_content===false) {
						$included_content = 'Templater could not include file: "'.$toinclude.'" position '.$posb.' in "'.$this->file.'" called by '.debug_backtrace()[0]['function'].'() in file :"'.debug_backtrace()[0]['file'].'" at line: '.debug_backtrace()[0]['line'];
						trigger_error($included_content,E_USER_NOTICE);
				}	// REPLACE TAG with file content
					$output = substr_replace($output,$included_content,$posb,($pose + $posecc) - $posb);
				}	
			
			}
			// Set, arrays or regular $values as paired before by set function
            foreach ($this->values as $key => $value) 
			{
            	$tagToReplace = "{"."$"."{$key}"."}";
            	if  (is_array($value)) 
				{
					$value_index = 0;
					while (($posb = strpos($output,$tagToReplace))!==false) 
					{
						if ($value->count() < $value_index) 
							break;
						$indexed_value = $value->{$value_index};
						$output = substr_replace($tagToReplace,$indexed_value,$posb);
						$value_index++;
					}
				}
				else $output = str_replace($tagToReplace, $value, $output);
            }
			// Eval PHP scripts NOTE: using replaced values by set! consider as server side "javascript" replacement.
			// Here could do loops evaluate more complicated variables, include files, 
            while (($posb = strpos($output,"<"."?php"))!==false) 
			{
					$pose = strpos($output,"?".">",$posb);
				if ($pose!==false) {
					$posbcc = 5; $posecc = 2;
					//remove heading spaces
					while ($output[$posb+$posbcc]==' ') {$posbcc++;}
					//remove tailing spaces
					while ($output[$pose-1]==' ') {$posecc++; $pose--;}
					//extract string for evaluation
					$toeval = substr($output,$posb + $posbcc,$pose - ($posb + $posbcc));
					//save existing echo buffer for a while
					$buffered_len = ob_get_length();
					if ($buffered_len!==false ? $buffered_len > 0 : false) {
						$buffered = ob_get_clean();
						}
					//redirect PHP echo into separate buffer
					ob_start();
					eval($toeval);
					$evaluated = ob_get_clean();
					$output = substr_replace($output,$evaluated,$posb,($pose + $posecc) - $posb);
					//restore PHP parent echo buffer
					if ($buffered_len!==false ? $buffered_len > 0 : false) { 
						ob_start(); echo $buffered; 
						}
				}	
			}
			// for, after including files, after replacing variables, evaluating srcipts this place is for copy-pasting results
			//TODO for(), need to rethink where to put it, before, after?
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