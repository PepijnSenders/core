<?php
/**
 * Dump
 */
namespace Sledgehammer;
/**
 * Parses a var_dump() and renders a syntax highlighted version of var_export()
 *
 * Usage:
 *   dump($var);
 * or in a VirtualFolder:
 *  return new Dump($var);
 *
 * (Compatible with the View interface from MVC)
 *
 * @package Core
 */
class Dump extends Object {

	/**
	 * The variable to display.
	 * @var mixed
	 */
	private $variable;

	/**
	 * The trace from where the `new Dump()` originated.
	 * @var array|null
	 */
	private $trace;

	/**
	 * Detected xdebug 2.2.0 var_dump() output.
	 * @var bool
	 */
	private static $xdebug = null;

	/**
	 * Colors based on Tomorrow Night
	 * @link https://github.com/ChrisKempson/Tomorrow-Theme
	 * @var array
	 */
	private static $colors = array(
		'background' => '#1d1f21',
		'foreground' => '#c5c8c6', // lightgray: =>,  {, (
		'current' => '#282a2e', // darkgray

		'class' => '#f0c674', // yellow
		'attribute' => '#cc6666', // red
		'method' => '#81a2be', // blue
		'keyword' => '#b294bb', // purple
		'string' => '#b5bd68', // green: "hello"
		'number' => '#8abeb7', // agua: 123
		'symbol' => '#de935f', // orange: {true, null}
		'comment' => '#969896', // gray
	);

	/**
	 * Constructor
	 * @param mixed $variable  The variable to display on render()
	 */
	function __construct($variable) {
		$this->variable = $variable;
		$trace = debug_backtrace();
		$file = $trace[0]['file'];
		$line = $trace[0]['line'];
		$this->trace = array(
			'invocation' => 'new '.__CLASS__,
			'file' => $file,
			'line' => $line,
		);
	}

	/**
	 * View::render() interface
	 * @return void
	 */
	function render() {
		$this->dump($this->variable, $this->trace);
	}

	/**
	 * Dumps information about a variable, like var_dump() but with improved syntax and coloring.
	 *
	 * @param mixed $variable
	 * @param array|null $trace
	 */
	static function dump($variable, $trace = null) {
		self::renderTrace($trace);

		$style = array(
			'margin: 0 5px 18px 5px',
			'padding: 10px 15px 15px 15px',
			'line-height: 14px',
			'background: '.self::$colors['background'],
			'border-radius: 0 0 4px 4px',
			'font: 10px/13px Monaco, monospace',
			'color: '.self::$colors['foreground'],
			'-webkit-font-smoothing: none',
			'font-smoothing: none',
			'overflow-x: auto',
			// reset
			'text-shadow: none',
			'text-align: left',
			'box-shadow: none',
		);

		echo "<pre style=\"".implode(';', $style)."\">\n";
		$old_value = ini_get('html_errors');
		ini_set('html_errors', false); // Forces xdebug < 2.2.0 to use render with the internal var_dump()
		ob_start();
		if (self::$xdebug === null) {
			// Detect xdebug 2.2 output
			var_dump(array('' => null));
			self::$xdebug = (strpos(ob_get_clean(), "'' =>") !== false);
			ob_start();
		}
		var_dump($variable);
		$output = rtrim(ob_get_clean());
		try {
			self::renderVardump($output);
		} catch (\Exception $e) {
			report_exception($e);
		}
		echo "\n</pre>\n";
		ini_set('html_errors', $old_value);
	}

	/**
	 * De vardump van kleuren voorzien.
	 * Retourneert de positie tot waar de gegevens geparsed zijn
	 *
	 * @param string $data the var_dump() output.
	 * @param int $indenting The the number of leading spaces
	 * @return int the number of characters the variable/array/object occupied in the var_dump() output
	 */
	private static function renderVardump($data, $indenting = 0) {
		if ($data[0] == '&') {
			$data = substr($data, 1);
			$pos = 1;
			echo '&amp;';
		} else {
			$pos = 0;
		}
		if (substr($data, 0, 4) == 'NULL') {
			self::renderType('null', 'symbol');
			return $pos + 4;
		}
		if (substr($data, 0, 11) == '*RECURSION*') {
			self::renderType('*RECURSION* ', 'keyword');
			self::renderType('// This variable is already shown', 'comment');
			return $pos + 11;
		}
		$bracketStart = strpos($data, '(');
		if ($bracketStart === false) {
			throw new \Exception('Unknown datatype "'.$data.'"');
		}
		$bracketEnd = strpos($data, ')', $bracketStart);
		$type = substr($data, 0, $bracketStart);
		$length = substr($data, $bracketStart + 1, $bracketEnd - $bracketStart - 1);
		$pos += $bracketEnd + 1;

		if (self::$xdebug && substr($type, 0, 5) === 'class') {
			$type = 'object';
		}

		// primitive types
		switch ($type) {

			// boolean (true en false)
			case 'bool':
				self::renderType($length, 'symbol');
				return $pos;

			// numbers (int, float)
			case 'int':
			case 'float':
				self::renderType($length, 'number');
				return $pos;

			// text (string)
			case 'string':
				$text = substr($data, $bracketEnd + 3, $length);
				self::renderType($text, 'string');
				return $pos + $length + 3;

			// Resources (file, gd, curl)
			case 'resource':
				$resource = 'Resource#'.$length;
				$pos = strpos($data, "\n");

				if ($pos === false) {
					$pos = strlen($data);
				} else {
					$data = substr($data, 0, $pos);
				}

				$resource.= preg_replace('/.*\(/', ' (', $data);
				$resource = substr($resource, 0);
				self::renderType($resource, 'constant');
				return $pos;

			// Arrays
			case 'array':
				if ($length == 0) {// Empty array?
					self::renderType('array', 'method');
					echo '()';
					return $pos + $indenting + 4;
				}
				$data = substr($data, $bracketEnd + 4); // ') {\n' eraf halen
				self::renderType('array', 'method');
				echo "(\n";
				if (self::$xdebug && preg_match('/^\s+\.\.\.\n/', $data, $matches)) {// xdebug limit reached?
					echo str_repeat(' ', $indenting + 4), "...\n";
					echo str_repeat(' ', $indenting);
					echo ')';
					return $pos + $indenting - 2 + strpos($data, '}');
				}
				$indenting += 2;
				for ($i = 0; $i < $length; $i++) {// De elementen
					echo str_repeat(' ', $indenting);
					if (self::$xdebug) {
						$data = substr($data, $indenting); // strip spaces
						$arrowPos = strpos($data, " =>\n");
						$index = substr($data, 0, $arrowPos);
						if ($index[0] === '[') { // numeric index?
							self::renderType(substr($index, 1, -1), 'number');
						} elseif ($index[0] == "'") {
							// @todo detect " =>\n" in the index
							self::renderType(substr($index, 1, -1), 'string');
						} else {
							throw new InfoException('Invalid index', array('index' => $index));
						}
						echo ' => ';
						$pos += strlen($index) + 5;
						$data = substr($data, $arrowPos + 4 + $indenting);
					} else {
						$data = substr($data, $indenting + 1); // spaties en [ eraf halen
						$arrowPos = strpos($data, ']=>');
						$index = substr($data, 0, $arrowPos);

						if ($index[0] == '"') { // assoc array?
							self::renderType(substr($index, 1, -1), 'string');
						} else {
							self::renderType($index, 'number');
						}
						echo ' => ';
						$data = substr($data, $arrowPos + 4 + $indenting);
						$pos += strlen($index) + 6;
					}
					$elementLength = self::renderVardump($data, $indenting);
					$data = substr($data, $elementLength + 1);
					$pos += $elementLength + ($indenting * 2);
					echo ",\n";
				}
				$indenting -= 2;
				$pos += 4 + $indenting;
				echo str_repeat(' ', $indenting);
				echo ')';
				return $pos;

			// Objects
			case 'object':
				if (self::$xdebug) {
					preg_match('/^class (.+)#/', $data, $matches);
					$object = $matches[1];
				} else {
					$data = substr($data, $bracketEnd + 1);
					$object = $length;
					$bracketStart = strpos($data, '(');
					if ($bracketStart === false) {
						throw new \Exception('Unexpected object notation "'.$object.'"');
					}
					$bracketEnd = strpos($data, ')', $bracketStart);
					$type = substr($data, 0, $bracketStart);
					$length = substr($data, $bracketStart + 1, $bracketEnd - $bracketStart - 1);
				}
				$data = substr($data, $bracketEnd + 4); // ' {\n' eraf halen
				self::renderType($object, 'class');
				if ($length == 0) { // Geen attributen?
					return $pos + $bracketEnd + strpos($data, '}') + 5; // tot '}\n' eraf halen.
				}
				echo " {\n";
				if (self::$xdebug && preg_match('/^\s+\.\.\.\n/', $data, $matches)) { // xdebug limit reached?
					echo str_repeat(' ', $indenting + 4), "...\n";
					echo str_repeat(' ', $indenting);
					self::renderType('}', 'class');
					return $pos + strpos($data, '}') + 2;
				}
				$indenting += 2;
				for ($i = 0; $i < $length; $i++) { // attributes
					echo str_repeat(' ', $indenting);
					if (self::$xdebug) {
						$data = substr($data, $indenting); // strip spaces
						$arrowPos = strpos($data, " =>\n");
						$attribute = substr($data, 0, $arrowPos);
						$data = substr($data, $arrowPos + 4 + $indenting);
						$pos += strlen($attribute) + 4;
					} else {
						$data = substr($data, $indenting + 1); // strip spaces and [
						$arrowPos = strpos($data, ']=>');
						$attribute = substr($data, 0, $arrowPos);
						$data = substr($data, $arrowPos + 4 + $indenting);
						$pos += strlen($attribute) + 6;
					}
					self::renderAttribute($attribute);
					echo ' -> ';
					if (self::$xdebug && preg_match('/^\s+\.\.\.\n\n/', $data, $matches)) { // xdebug limit reached?
						echo "\n", str_repeat(' ', $indenting + 4), "...\n\n";
						echo str_repeat(' ', $indenting - 2);
						echo '}';
						return $pos + strlen($matches[0]);
					}
					$elementLength = self::renderVardump($data, $indenting);
					$data = substr($data, $elementLength + 1);
					echo ",\n";
					$pos += $elementLength + ($indenting * 2);
				}
				$indenting -= 2;
				$pos += $bracketEnd + 5 + $indenting;
				echo str_repeat(' ', $indenting);
				echo '}';
				return $pos;
				break;

			default:
				throw new \Exception('Unknown datatype "'.$type.'"');
		}
	}

	/**
	 * Achterhaald het bestand en regelnummer waarvan de dump functie is aangeroepen
	 * @param array $trace
	 */
	private static function renderTrace($trace = null) {
		if ($trace === null) {
			$trace = array(
				'invocation' => 'dump'
			);
			$backtrace = debug_backtrace();
			for ($i = count($backtrace) - 1; $i >= 0; $i--) {
				if (isset($backtrace[$i]['function']) && strtolower($backtrace[$i]['function']) == 'dump') {
					if (isset($backtrace[$i]['file'])) {
						// Parameter achterhalen
						$trace['file'] = $backtrace[$i]['file'];
						$trace['line'] = $backtrace[$i]['line'];
						break;
					}
				}
			}
		}
		$style = array(
			'font: 13px/22px \'Helvetica Neue\', Helvetica, sans-serif',
			'border-radius: 4px 4px 0 0',
			'background-color: '.self::$colors['current'],
			'margin: 15px 5px 0 5px',
			'padding: 3px',
			'padding-left: 9px',
			'color: '.self::$colors['foreground'],
			'text-shadow: 0 1px 0 #000',
		);
		echo '<div style="'.implode(';', $style).'">';
		self::renderType($trace['invocation'], 'comment');
		echo '(<span style="margin: 0 3px;">';

		$file = file($trace['file']);
		$line = $file[($trace['line'] - 1)];
		$line = preg_replace('/.*dump\(/i', '', $line); // Alles voor de dump aanroep weghalen
		$argument = preg_replace('/\);.*/', '', $line); // Alles na de dump aanroep weghalen
		$argument = trim($argument);
		if (preg_match('/^\$[a-z_]+[a-z_0-9]*$/i', $argument)) { // $var?
			self::renderType($argument, 'attribute');
		} elseif (preg_match('/^(?P<function>[a-z_]+[a-z_0-9]*)\((?<arguments>[^\)]*)\)$/i', $argument, $matches)) { // function()?
			self::renderType($matches['function'], 'method');
			echo '(', htmlentities($matches['arguments'], ENT_COMPAT, Framework::$charset), ')';
		} elseif (preg_match('/^(?P<object>\$[a-z_]+[a-z_0-9]*)\-\>(?P<attribute>[a-z_]+[a-z_0-9]*)(?P<element>\[.+\])$/i', $argument, $matches)) { // $object->attribute or $object->attribute[12]?
			self::renderType($matches['object'], 'class');
			echo '->';
			self::renderType($matches['attribute'], 'attribute');
			if ($matches['element']) {
				echo '['.substr($matches['element'], 1, -1).']';
			}
		} elseif (preg_match('/^(?P<object>\$[a-z_]+[a-z_0-9]*)\-\>(?P<method>[a-z_]+[a-z_0-9]*)\((?<arguments>[^\)]*)\)$/i', $argument, $matches)) { // $object->method()?
			self::renderType($matches['object'], 'class');
			echo '->';
			self::renderType($matches['method'], 'method');
			echo '(', htmlentities($matches['arguments'], ENT_COMPAT, Framework::$charset), ')';
		} else {
			echo htmlentities($argument, ENT_COMPAT, Framework::$charset);
		}
		echo '</span>)&nbsp;';
		self::renderType(' in ', 'comment');
		echo '/', str_replace('\\', '/', str_replace(PATH, '', $trace['file']));
		self::renderType(' on line ', 'comment');
		echo $trace['line'];
		echo "</div>\n";
	}

	/**
	 * Haal de scope uit de attribute string.
	 *
	 * @param string $attribute Bv '"log", '"error_types:private" of '"error_types":"ErrorHandler":private'
	 */
	static private function renderAttribute($attribute) {
		if (self::$xdebug) {
			if (preg_match('/(public|protected|private) \$(.+)$/', $attribute, $matches)) {
				self::renderType($matches[2], 'attribute');
				if ($matches[1] !== 'public') {
					echo '<span style="font-size:9px">:';
					$this->renderColor($matches[1], 'comment');
					echo '</span>';
				}
				return;
			} else {
				throw new InfoException('Invalid attribute', array('attribute' => $attribute));
			}
		}
		$parts = explode(':', $attribute);
		$partsCount = count($parts);
		switch ($partsCount) {

			case 1: // Is de scope niet opgegeven?
				self::renderType(substr($attribute, 1, -1), 'attribute');
				break;

			case 2:
				if (substr($parts[1], -1) == '"') { // Sinds php 5.3 is wordt ':protected' en ':private' NA de '"' gezet ipv ervoor
					// php < 5.3
					self::renderType(substr($parts[0], 1), 'attribute');
					echo '<span style="font-size:9px">:';
					self::renderType(substr($parts[1], 0, -1), 'comment');
					echo '</span>';
				} else { // php >= 5.3
					self::renderType(substr($parts[0], 1, -1), 'attribute');
					echo '<span style="font-size:9px">:';
					self::renderType($parts[1], 'comment');
					echo '</span>';
				}
				break;

			case 3: // Sinds 5.3 staat bij er naast :private ook van welke class deze private is. bv: "max_string_length_backtrace":"ErrorHandler":private
				self::renderType(substr($parts[0], 1, -1), 'attribute');
				echo '<span style="font-size:9px" title="'.htmlentities(substr($parts[1], 1, -1)).'">:';
				self::renderType($parts[2], 'comment');
				echo '</span>';
				break;

			default:
				throw new InfoException('Unexpected number of parts: '.$partsCount, $parts);
		}
	}

	/**
	 * Render the contents in the color of the type.
	 *
	 * @param string $data
	 * @param string $type
	 */
	private static function renderType($data, $type) {
		if ($type === 'string') {
			$data = '&#39;'.htmlspecialchars($data).'&#39;';
		}
		echo '<span style="color: ', self::$colors[$type], '">', $data, '</span>';
	}

}

?>