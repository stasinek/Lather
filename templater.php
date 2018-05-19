<?php
	/**
	 * Simple template engine class (use {$tag} tags in your templates).
	 * 
	 * @link http://www.broculos.net/ Broculos.net Programming Tutorials
	 * @author Nuno Freitas <nunofreitas@gmail.com>
	 * @version 1.0
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
				$msg = "Error loading template file ($this->file).";
				trigger_error( $msg,E_USER_NOTICE);
            	return $msg.'<br>';
            }
            $output = file_get_contents($this->file);
            
            while (($posb = strpos($output,"{"."?include"))!==false) {
					$pose = strpos($output,"?"."}",$posb);
				if ($pose!==false) {
					$posbcc = 10; $posecc = 3;
					// incluce(, include (, include <, include[, include(" and so on..
					while ($output[$posb+$posbcc]==' ') $posbcc++;
					if ($output[$posb+$posbcc]== '(' || $output[$posb+$posbcc]== '[' || $output[$posb+$posbcc]=='<') $posbcc++;
					while ($output[$posb+$posbcc]==' ') $posbcc++;
					if ($output[$posb+$posbcc]=='\'' || $output[$posb+$posbcc]=='\"') $posbcc++;
					while ($output[$posb+$posbcc]==' ') $posbcc++;
					// incluce -> >,],),"),') and so on..
					while ($output[$pose-1]==' ') {$posecc++;$pose--;}
					if ($output[$pose-1]== ')' || $output[$pose-1]== ']' || $output[$pose-1]=='>') {$posecc++;$pose--;}
					while ($output[$pose-1]==' ') {$posecc++;$pose--;}
					if ($output[$pose-1]=='\'' || $output[$pose-1]=='\"') {$posecc++;$pose--;}
					while ($output[$pose-1]==' ') {$posecc++;$pose--;}

					$toinclude = substr($output,$posb + $posbcc,$pose - ($posb + $posbcc));
					$included_content = file_get_contents($toinclude);
					if ($included_content===false) {
						$included_content = 'Template "'.$this->file.'" at position: '.$posb.' could not include file: "'.$toinclude.'"';
					}
					$output = substr_replace($output,$included_content,$posb,($pose + $posecc) - $posb);
				}	
			
			}
            foreach ($this->values as $key => $value) {
            	$tagToReplace = "{"."$"."{$key}"."}";
            	$output = str_replace($tagToReplace, $value, $output);
            }
            while (($posb = strpos($output,"{"."?php"))!==false) {
					$pose = strpos($output,"?"."}",$posb);
				if ($pose!==false) {
					$posbcc = 5; $posecc = 2;
					//remove spaces
					while ($output[$posb+$posbcc]==' ') {$posbcc++;}
					while ($output[$pose-1]==' ') {$posecc++; $pose--;}

					$toeval = substr($output,$posb + $posbcc,$pose - ($posb + $posbcc));
					$buffered_len = ob_get_length();
					if ($buffered_len!==false ? $buffered_len > 0 : false) {
						$buffered = ob_get_clean();
						}
					ob_start();
					eval($toeval);
					$evaluated = ob_get_clean();
					$output = substr_replace($output,$evaluated,$posb,($pose + $posecc) - $posb);
					if ($buffered_len!==false ? $buffered_len > 0 : false) { 
						ob_start(); echo $buffered; 
						}
				}	
			
			}
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
            		? "Error, incorrect type - expected Template."
            		: $template->output();
            	$output .= $content . $separator;
            }
            return $output;
        }
    }

?>