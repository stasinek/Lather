<?php
/**
 * Lather - simple PHP template engine.
 *
 * @link   https://github.com/stasinek/lather
 * @author Nuno Freitas <nunofreitas@gmail.com> (original, Broculos.net)
 * @author SSTSOFT.pl (lather modifications)
 * @modified by SSTSOFT.pl {$} instead of just [@] to make it more feel alike Latte, 
 * @Added basic support for arrays:
 * @For variable $array set('array',$array) each ocurence of $array will be replaced by next element of = $array->{$variable}; 
 * @In future hope to change it to be more sophisticated,
 * @Will add support for $array[indexes] and it's {$array}->{$values}
 * @v0.1 addded support for <?php?> & <?include()?>
 * @v0.2 tweaked <?include()?> to support relative path ../ ./ or simmilar to CSS @import url() -> include()
 * @v0.3 Added function: "attatch" files to keys, extend "set", combine output using multiple sources using "add"
 * @version 1.0 - HUGE secure rewrite, same template syntax and the same public API
 *
 * Template syntax (unchanged):
 *   ${key}  {$key}  {@key}  @{key}   value of $t->set('key', ...)
 *   <?include file.tmpl?>            insert another template file here (missing file = nothing)
 *   <?require file.tmpl?>            same, but a missing file is an error
 *   <?import file.tmpl?>             render another template with a copy of the values, insert the result
 *   <?php ... ?>  <?= ... ?>         inline PHP - DISABLED unless Template::$ALLOWPHP = true
 *   Paths may be written as  file.tmpl  ("file.tmpl")  ('file.tmpl')  (file.tmpl)  <file.tmpl>  [file.tmpl]
 *   and are relative to the template that contains them.
 *
 * Security model (what changed in 1.0):
 *   1. Values are DATA, never template code. A template is parsed into tokens first; values are
 *      inserted into the finished token stream and are never parsed again. A user name like
 *      "<?include ../config.php?>" or "<?php system('id'); ?>" is printed as harmless text.
 *   2. Values are HTML-escaped. To insert trusted HTML use Template::html($str), set_html(), or pass
 *      the result of another template: output() and merge() return a TemplateHtml object, so
 *      $layout->set('content', $page->output()) keeps working and is NOT escaped.
 *   3. <?include/require/import?> can only reach files with an allowed extension (.tmpl .tpl .html .htm)
 *      that are inside the template root directory (Template::setRoot(), default: the directory of the
 *      first template you create). No ../ escapes, no absolute paths, no .php source leaks.
 *   4. Inline PHP is off by default. If you enable it, the code still comes only from template files,
 *      never from values.
 *   5. Fail closed: a missing "?>", a path outside the root, a missing <?require?>, an include cycle or
 *      too deep nesting throws TemplateError. Nothing is half-printed, nothing loops forever, and
 *      error details (paths) go to the PHP error log, not to the page.

 * MIGRATION NOTES (0.3 -> 1.0) - where the old code went
 *
 * Why a rewrite: 0.3 replaced variables first and then searched the RESULT for
 * <?include / <?php, so a value could turn into a directive. 1.0 tokenizes the
 * template source first and inserts values last, into finished tokens.
 *
 *   output()               ->  render(): tokenize() -> expand() -> values into tokens.
 *                              output() now wraps render() and never throws.
 *   replace_variables()    ->  tokenize_text() + render_var() / render_value()
 *                              (HTML escaping, arrays: one element per occurrence)
 *   import_files()         ->  tokenize() + clean_path() + resolve()
 *                              (allowed extensions only, confined to Template::$root)
 *   eval_scripts()         ->  run_php(), disabled unless Template::$allowPhp = true
 *   path_eval()            ->  same function and signature, fixed drive letters (C:)
 *   locked / microtime()   ->  removed (PHP objects are not shared between processes)
 *   Stack class            ->  removed (not used by the template engine)
 *   error_handler()        ->  removed; messages go through report_status() to the PHP log
 *   attatch()              ->  fixed ($key typo), alias attach() added
 *   merge(), output()      ->  return TemplateHtml (behaves like a string, inserted unescaped)
 *
 * New: Template::setRoot(), set_html(), Template::html(), get(), render() (throws TemplateError).
 */

if (!function_exists('path_eval')) {
	/**
	 * Normalizes $path relative to $base (string only, does not touch the filesystem).
	 * Kept for backwards compatibility.
	 */
	function path_eval($path, $base = null)
	{
		if ($base === null) $base = getcwd();
		$path = str_replace('\\', '/', $path);
		$base = str_replace('\\', '/', $base);
		$isAbs = ($path !== '' && $path[0] === '/') || preg_match('~^[A-Za-z]:/~', $path);
		$prefix = '';
		if ($isAbs) {
			if (preg_match('~^([A-Za-z]:)/~', $path, $m)) { $prefix = $m[1]; $path = substr($path, 2); }
			$stack = array();
		} else {
			if (preg_match('~^([A-Za-z]:)/~', $base, $m)) { $prefix = $m[1]; $base = substr($base, 2); }
			$stack = array_values(array_filter(explode('/', $base), 'strlen'));
		}
		foreach (explode('/', $path) as $part) {
			if ($part === '' || $part === '.') continue;
			if ($part === '..') array_pop($stack);
			else $stack[] = $part;
		}
		$res = $prefix . '/' . implode('/', $stack);
		return DIRECTORY_SEPARATOR === '/' ? $res : str_replace('/', DIRECTORY_SEPARATOR, $res);
	}
}

/** Thrown when a template cannot be rendered safely. The message is meant for logs, not for visitors. */
class TemplateError extends RuntimeException {}

/** Trusted HTML. Inserted into templates without escaping. Behaves like a string. */
class TemplateHtml
{
	private $html;
	public function __construct($html) { $this->html = (string)$html; }
	public function __toString() { return $this->html; }
}

class Template
{
	/** Directory that <?include?> / <?require?> / <?import?> may read from. */
	public static $root = null;
	/** File extensions that template directives may load. */
	public static $allowedExtensions = array('tmpl', 'tpl', 'html', 'htm');
	/** Execute <?php ?> and <?= ?> blocks found in template FILES. Off by default. */
	public static $ALLOWPHP = false;
	/** Maximum nesting of include/require/import. */
	public static $maxDepth = 16;
	/** Escape values with htmlspecialchars (recommended). */
	public static $escape = true;

	protected $file;
	protected $values = array();
	protected $appended = '';
	protected $opened = false;
	protected $source = null;
	protected $depth = 0;
	protected $chain = array();
	public $debug = false;
	public $error_list = array();

	/**
	 * @param string   $file   template file (from your PHP code - trusted)
	 * @param Template $parent optional: start with a copy of the parent's values
	 */
	public function __construct($file, $parent = null)
	{
		$this->file = $file;
		if ($parent instanceof Template) {
			$this->values = $parent->values;
			$this->depth = $parent->depth;
			$this->chain = $parent->chain;
		}
		if (self::$root === null) {
			$dir = realpath(dirname($file));
			if ($dir !== false) self::$root = $dir;
		}
	}

	/** Sets the directory template directives are confined to. Call once at startup. */
	public static function setRoot($dir)
	{
		$real = realpath($dir);
		if ($real === false || !is_dir($real)) throw new TemplateError('Template root does not exist: ' . $dir);
		self::$root = $real;
	}

	/** Wraps trusted HTML so it is not escaped. */
	public static function html($html) { return new TemplateHtml($html); }

	// ------------------------------------------------------------------------------------------
	// Values
	// ------------------------------------------------------------------------------------------

	/** Value for ${key}. Strings are escaped; TemplateHtml / Template / output() are inserted as HTML. */
	public function set($key, $value)
	{
		if ($this->debug) $this->report_status('set("' . $key . '")', E_USER_NOTICE);
		$this->values[$key] = $value;
	}

	/** Same as set(), but the value is trusted HTML. */
	public function set_html($key, $html) { $this->set($key, new TemplateHtml($html)); }

	/** Appends to an existing value (text or HTML); for arrays adds the next element. */
	public function add($key, $value)
	{
		if ($this->debug) $this->report_status('add("' . $key . '")', E_USER_NOTICE);
		if (!isset($this->values[$key])) { $this->values[$key] = $value; return; }
		$old = $this->values[$key];
		if (is_array($old)) { $old[] = $value; $this->values[$key] = $old; return; }
		$this->values[$key] = new TemplateHtml($this->render_value($old) . $this->render_value($value));
	}

	public function erase($key) { $this->values[$key] = ''; }

	public function get($key) { return isset($this->values[$key]) ? $this->values[$key] : null; }

	/**
	 * Reads a file and stores its contents under $key as trusted HTML,
	 * or appends it to the output when $key is null. The file name comes from your code.
	 */
	public function attatch($file, $key = null)
	{
		$contents = @file_get_contents($file);
		if ($contents === false) {
			$this->report_status('attatch(): cannot read "' . $file . '"');
			return false;
		}
		if ($key === null) $this->appended .= $contents;
		else $this->set($key, new TemplateHtml($contents));
		return true;
	}
	/** Correctly spelled alias. */
	public function attach($file, $key = null) { return $this->attatch($file, $key); }

	// ------------------------------------------------------------------------------------------
	// Rendering
	// ------------------------------------------------------------------------------------------

	/** Loads the template file. Called automatically by output(). */
	public function open()
	{
		$src = @file_get_contents($this->file);
		if ($src === false) {
			$this->report_status('open(): cannot read "' . $this->file . '"');
			return $this->opened = false;
		}
		$this->source = $src;
		return $this->opened = true;
	}

	/**
	 * Renders the template. Returns TemplateHtml (usable as a string: echo, concatenation, set()).
	 * On a broken or unsafe template returns empty HTML and logs the reason; set $debug or use
	 * render() to get the TemplateError instead.
	 */
	public function output()
	{
		try {
			return $this->render();
		} catch (TemplateError $e) {
			$this->report_status($e->getMessage(), E_USER_WARNING);
			return new TemplateHtml('');
		}
	}

	/** Like output(), but throws TemplateError. */
	public function render()
	{
		if (!$this->opened && !$this->open()) throw new TemplateError('Cannot read template "' . $this->file . '"');
		$real = realpath($this->file);
		$chain = $this->chain;
		if ($real !== false) {
			if (in_array($real, $chain, true)) throw new TemplateError('Include cycle at "' . $this->file . '"');
			$chain[] = $real;
		}
		$counters = array();
		$tokens = $this->expand($this->tokenize($this->source, $this->file), dirname($real !== false ? $real : $this->file), $this->depth, $chain);
		$out = '';
		foreach ($tokens as $t) {
			switch ($t[0]) {
				case 'text':
					$out .= $t[1];
					break;
				case 'var':
					$out .= $this->render_var($t[1], $t[2], $counters);
					break;
				case 'import':
					$child = new Template($t[1], $this);
					$child->depth = $this->depth + count($chain);
					$child->chain = $chain;
					$out .= $child->render();
					break;
				case 'php':
					$out .= $this->run_php($t[1], $t[2]);
					break;
			}
		}
		return new TemplateHtml($out . $this->appended);
	}

	public function content() { return $this->output(); }

	public function print_content() { echo $this->output(); }

	/**
	 * Renders several templates and joins them. Returns TemplateHtml.
	 * @param Template[] $templates
	 */
	public static function merge($templates, $separator = "\n")
	{
		$parts = array();
		foreach ($templates as $template) {
			if (!($template instanceof Template)) {
				trigger_error('Template::merge(): expected Template, got ' . (is_object($template) ? get_class($template) : gettype($template)), E_USER_WARNING);
				continue;
			}
			$parts[] = (string)$template->output();
		}
		return new TemplateHtml(implode($separator, $parts) . (count($parts) ? $separator : ''));
	}

	// ------------------------------------------------------------------------------------------
	// Parsing - template SOURCE only. Values never pass through here.
	// ------------------------------------------------------------------------------------------

	const VAR_RE = '/\$\{\s*([A-Za-z_][A-Za-z0-9_.\-]*)\s*\}|\{\$\s*([A-Za-z_][A-Za-z0-9_.\-]*)\s*\}|\{@\s*([A-Za-z_][A-Za-z0-9_.\-]*)\s*\}|@\{\s*([A-Za-z_][A-Za-z0-9_.\-]*)\s*\}/';

	/** Splits template source into tokens: text, var, include/require/import, php. */
	protected function tokenize($src, $file)
	{
		$tokens = array();
		$pos = 0;
		$len = strlen($src);
		while ($pos < $len) {
			$open = strpos($src, '<?', $pos);
			$textEnd = $open === false ? $len : $open;
			if ($textEnd > $pos) $this->tokenize_text(substr($src, $pos, $textEnd - $pos), $tokens);
			if ($open === false) break;

			if (!preg_match('/\G<\?(?:(include|require|import|php)(?=[\s(<\[\'"]|\?>)|(=))/Ai', $src, $m, 0, $open)) {
				/* Not a directive (e.g. an XML declaration): keep it as plain text. */
				$tokens[] = array('text', '<?');
				$pos = $open + 2;
				continue;
			}
			$close = strpos($src, '?>', $open + 2);
			if ($close === false) {
				throw new TemplateError('Unclosed "' . $m[0] . '" (missing "?>") in "' . $file . '" at byte ' . $open);
			}
			$kind = isset($m[2]) && $m[2] === '=' ? '=' : strtolower($m[1]);
			$body = substr($src, $open + strlen($m[0]), $close - $open - strlen($m[0]));
			if ($kind === 'php' || $kind === '=') {
				$tokens[] = array('php', $body, $kind === '=');
			} else {
				$tokens[] = array($kind, $this->clean_path($body), $file);
			}
			$pos = $close + 2;
		}
		return $tokens;
	}

	protected function tokenize_text($text, &$tokens)
	{
		$last = 0;
		if (preg_match_all(self::VAR_RE, $text, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
			foreach ($mm as $m) {
				$off = $m[0][1];
				if ($off > $last) $tokens[] = array('text', substr($text, $last, $off - $last));
				$key = '';
				for ($g = 1; $g <= 4; $g++) if (isset($m[$g]) && $m[$g][1] >= 0 && $m[$g][0] !== '') { $key = $m[$g][0]; break; }
				$tokens[] = array('var', $key, $m[0][0]);
				$last = $off + strlen($m[0][0]);
			}
		}
		if ($last < strlen($text)) $tokens[] = array('text', substr($text, $last));
	}

	/** "  ( 'file.tmpl' )  " -> file.tmpl */
	protected function clean_path($s)
	{
		$s = trim($s);
		$pairs = array('(' => ')', '<' => '>', '[' => ']', '"' => '"', "'" => "'");
		do {
			$changed = false;
			$s = trim($s);
			if (strlen($s) >= 2 && isset($pairs[$s[0]]) && substr($s, -1) === $pairs[$s[0]]) {
				$s = substr($s, 1, -1);
				$changed = true;
			}
		} while ($changed);
		return trim($s);
	}

	/** Replaces include/require tokens with the tokens of the included files (recursively). */
	protected function expand($tokens, $baseDir, $depth, $chain)
	{
		$out = array();
		foreach ($tokens as $t) {
			if ($t[0] !== 'include' && $t[0] !== 'require' && $t[0] !== 'import') { $out[] = $t; continue; }
			if ($depth + count($chain) > self::$maxDepth) throw new TemplateError('Templates nested too deep at "' . $t[2] . '"');
			$path = $this->resolve($t[1], $baseDir, $t[2]);
			if ($path === null) {
				if ($t[0] === 'include') {
					$this->report_status('include skipped, file not found: "' . $t[1] . '" in "' . $t[2] . '"', E_USER_NOTICE);
					continue;
				}
				throw new TemplateError('Required template not found: "' . $t[1] . '" in "' . $t[2] . '"');
			}
			if ($t[0] === 'import') { $out[] = array('import', $path); continue; }
			if (in_array($path, $chain, true)) throw new TemplateError('Include cycle: "' . $t[1] . '" in "' . $t[2] . '"');
			$src = @file_get_contents($path);
			if ($src === false) throw new TemplateError('Cannot read "' . $path . '"');
			$sub = $this->tokenize($src, $path);
			$subChain = $chain;
			$subChain[] = $path;
			foreach ($this->expand($sub, dirname($path), $depth, $subChain) as $x) $out[] = $x;
		}
		return $out;
	}

	/**
	 * Resolves a directive path. Returns the real path, or null when the file does not exist.
	 * Throws on anything outside the root or with a forbidden extension.
	 */
	protected function resolve($rel, $baseDir, $from)
	{
		if ($rel === '' || strpos($rel, "\0") !== false) throw new TemplateError('Empty or invalid path in "' . $from . '"');
		$norm = str_replace('\\', '/', $rel);
		if ($norm[0] === '/' || preg_match('~^[A-Za-z]:~', $norm) || preg_match('~^[a-z][a-z0-9+.\-]*://~i', $norm)) {
			throw new TemplateError('Absolute paths and URLs are not allowed: "' . $rel . '" in "' . $from . '"');
		}
		$ext = strtolower(pathinfo($norm, PATHINFO_EXTENSION));
		if (!in_array($ext, self::$allowedExtensions, true)) {
			throw new TemplateError('File type not allowed: "' . $rel . '" in "' . $from . '"');
		}
		$root = self::$root;
		if ($root === null) throw new TemplateError('Template root is not set');
		$candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $norm);
		$real = realpath($candidate);
		// Check the logical path too, so a missing file outside the root is an error, not a silent skip.
		$logical = path_eval($norm, $baseDir);
		if (!$this->inside_root($logical, $root)) {
			throw new TemplateError('Path leaves the template root: "' . $rel . '" in "' . $from . '"');
		}
		if ($real === false || !is_file($real)) return null;
		if (!$this->inside_root($real, $root)) {          // e.g. a symlink pointing outside
			throw new TemplateError('Path leaves the template root: "' . $rel . '" in "' . $from . '"');
		}
		return $real;
	}

	protected function inside_root($path, $root)
	{
		$p = rtrim(str_replace('\\', '/', $path), '/');
		$r = rtrim(str_replace('\\', '/', $root), '/');
		if (DIRECTORY_SEPARATOR === '\\') { $p = strtolower($p); $r = strtolower($r); }
		return $p === $r || strpos($p, $r . '/') === 0;
	}

	// ------------------------------------------------------------------------------------------
	// Values -> HTML
	// ------------------------------------------------------------------------------------------

	protected function render_var($key, $literal, &$counters)
	{
		if (!array_key_exists($key, $this->values)) return $literal;   // unknown tag stays as written
		$v = $this->values[$key];
		if (is_array($v)) {
			// Each occurrence takes the next element (as documented in lather 0.2).
			$i = isset($counters[$key]) ? $counters[$key] : 0;
			$counters[$key] = $i + 1;
			$list = array_values($v);
			if (!isset($list[$i])) return '';
			$v = $list[$i];
		}
		return $this->render_value($v);
	}

	protected function render_value($v)
	{
		if ($v instanceof TemplateHtml) return (string)$v;
		if ($v instanceof Template) return (string)$v->output();
		if ($v === null || $v === false) return '';
		if ($v === true) return '1';
		if (is_array($v)) return '';
		if (is_object($v) && !method_exists($v, '__toString')) return '';
		$s = (string)$v;
		return self::$escape ? htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $s;
	}

	/** Runs inline PHP from a template FILE (only when Template::$ALLOWPHP is true). */
	protected function run_php($code, $isEcho)
	{
		if (!self::$ALLOWPHP) {
			throw new TemplateError('Inline PHP in "' . $this->file . '" is disabled (Template::$ALLOWPHP = false)');
		}
		$vars = $this->values;
		$t = $this;
		$fn = function () use ($code, $isEcho, $vars, $t) {
			extract($vars, EXTR_SKIP);
			if ($isEcho) eval('echo ' . rtrim(trim($code), ';') . ';');
			else eval($code . ';');
		};
		ob_start();
		try {
			$fn();
		} catch (Throwable $e) {
			ob_end_clean();
			throw new TemplateError('PHP error in "' . $this->file . '": ' . $e->getMessage());
		}
		return ob_get_clean();
	}

	// ------------------------------------------------------------------------------------------
	// Errors - to the PHP error log, never into the page
	// ------------------------------------------------------------------------------------------

	function format_status($operation = 'Unknown')
	{
		return 'Template: "' . $this->file . '" ' . $operation;
	}

	function report_status($status, $level = E_USER_NOTICE)
	{
		$this->error_list[] = $status;
		trigger_error('Lather: ' . $status, $level);
	}

	/** Prints collected messages - only in debug mode (they contain server paths). */
	function print_errors()
	{
		if (!$this->debug) return;
		echo '<ul style="float:left;text-align:left">';
		foreach ($this->error_list as $error) echo '<li>' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</li>';
		echo '</ul>';
	}
}
?>