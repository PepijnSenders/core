<?php
/**
 * Verantwoordelijk voor het on-the-fly inladen en declareren van classes en interfaces.
 * Dit verbeterd parsetijd/geheugenverbruik aanzienlijk, alleen de bestanden die je nodig hebt worden ge-include.
 *
 * @package Core
 */
namespace SledgeHammer;
class AutoLoader extends Object {

	public
		$standalone = true, // Bij true zal declareClass() een fout genereren als de class niet bekend is.
		$enableCache = true; // Bij true worden de resultaten (per module) gecached, de cache zal opnieuw opgebouwt worden als er bestanden gewijzigd of toegevoegd zijn.

	private
		$path, // De basismap
		$cachePath, // De map waar de cache bestanden worden opgeslagen.
		$classes = array(), // Array met class-definities
		$interfaces = array(), // Array met interface-definities
		$extractErrors = false, // bool Die aangeeft of er fouten zijn opgetreden bij het inlezen van definities. (Bij fouten wordt de uitkomst niet gecached)

		$defaultSettings = array(
			'mandatory_superclass' => false, // Controleer of "alle" objecten een superclass hebben
			'matching_filename' => true, // Controleer of de bestandnaam overeenkomst met de class name
			'mandatory_comment_block' => false, // Controleer op correct begin van de php bestanden
			'notify_on_multiple_definitions_per_file' => true, // Geen een notice als er meer dan 1 class gedefineerd wordt in een bestand.
			'revalidate_cache_delay' => 20, // Bij false wordt er elke run gecontrontroleerd of er bestanden gewijzigd zijn. Anders wordt er elke x seconden gecontroleerd.
			'ignore_all' => false, // Bij false worden de bestanden en submappen doorzocht op class en interface definities
			'ignore_folders' => '.git,.svn',
			'ignore_files' => '.DS_Store,.gitignore',
			'ignore_extensions' => true, // Negeer extensies anders dan *.php. Gebruik csv voor specifieke extensies
		),

		// Als de module een "classes" map heeft, dan worden de strict settings gebruikt
		$strictSettings = array(
			'mandatory_superclass' => true,
			'matching_filename' => true,
			'mandatory_comment_block' => true,
			'notify_on_multiple_definitions_per_file' => true,
			'revalidate_cache_delay' => 10,
			'ignore_all' => false,
			'ignore_folders' => '',
			'ignore_files' => '.DS_Store',
			'ignore_extensions' => 'swp,bak,backup', // Negeer bestanden met deze extensies
		);
	/**
	 * @param string $path  De basis map, vaak gelijk aan de PATH constante
	 */
	function __construct($path) {
		$this->path = $path;
		$this->cachePath = $path.'/tmp/AutoLoader/';
	}

	/**
	 * De class en interface definities van de applicatie in $this->path inladen.
	 * Als "{$this->path}/autoloader.db.php" bestaat, wordt deze ingeladen, anders worden de php-bestanden ge-inspecteert voor class en interface definities.
	 *
	 * @return void
	 */
	function init() {
		if (!file_exists($this->path.'autoloader.db.php')) {
			$modules = Framework::getModules();
			$this->inspectModules($modules);
		} else {
			include($this->path.'autoloader.db.php');
			if (!isset($classes) || !isset($interfaces)) {
				notice('$classes of $interfaces definitions not found in "'.$this->path.'autoloader.db.php"');
			}
			$this->classes = $classes;
			$this->interfaces = $interfaces;
		}
	}

	/**
	 * Een class declareren.
	 * Zal het bijbehorende bestand includen.
	 *
	 * @return bool
	 */
	function declareClass($class) {
		if (class_exists($class, false)) { // is de class al gedeclareerd?
			return true;
		}
		$info = $this->getInfo($class, !$this->standalone); // de gegevens van de class opzoeken
		if (!$info) {
			$namespaces = explode('\\', $class);
			$classWithoutNamespace = array_pop($namespaces);
			$originalNamespace = implode('\\', $namespaces);
			while (count($namespaces) > 0) {
				// Kijk een namespage hoger
				array_pop($namespaces);
				$classLevelUp = implode('\\', $namespaces). $classWithoutNamespace;
				if (!class_exists($classLevelUp)) {
					$infoLevelUp = $this->getInfo($classLevelUp, false);
					if ($infoLevelUp) {
						$this->declareClass($classLevelUp);
					}
				}
				$extends =  (count($namespaces) == 0) ? '\\' : implode('\\',$namespaces);
				$extends .= $classWithoutNamespace;
				$php = 'namespace '.$originalNamespace.";\n";
				$php .= 'class '.$classWithoutNamespace.' extends '.$extends." {\n}";
				notice('Generating class "'.$class.'" based on "'.$extends.'"');
				eval ($php); // try to duplicate the (global) class into the namespace

			}
			return false;
		}
		if (isset($info['extends'])) { // heeft dit object een parent?
			if (!$this->declareClass($info['extends'])) { // de parent proberen te declareren
				throw new Exception('Failed to declare parent: "'.$info['extends'].'" of class: "'.$class.'"');
			}
		}
		if (isset($info['implements'])) { // heeft dit object een interface?
			foreach ($info['implements'] as $interface) {
				if (!$this->declareInterface($interface)) { // de interface proberen te declareren
					throw new Exception('Failed to declare interface: "'.$interface.'" of class: "'.$class.'"');
				}
			}
		}
		if (!include_once($info['filename'])) { // Lukte het includen van het bestand?
			throw new Exception('Failed to include "'.$info['filename'].'"');
		}
		if (!class_exists($class, false)) {
			throw new Exception('A autoloader file is corrupt, class "'.$class.'" not found in "'.$info['filename'].'"');
		}
		return true;
	}

	/**
	 * Een interface declareren.
	 * Zal het bijbehorende bestand includen.
	 *
	 * @return bool
	 */
	function declareInterface($interface) {
		if (interface_exists($interface, false)) { // is de interface al gedeclareerd?
			return true;
		}
		$info = @$this->interfaces[$interface]; // Opzoeken of deze interface bekent in binnen de geopende libraries

		if (!$info) {
			warning('Unknown interface: "'.$interface.'"', array('Available interfaces' => implode(array_keys($this->interfaces), ', ')));
			return false;
		}
		if (isset($info['extends'])) {
			foreach ($info['extends'] as $super) {
				$this->declareInterface($super);
			}
		}
		$filename = $this->fullpath($info['filename']);
		if (!include($filename)) { // Lukte het includen van het bestand?
			throw new Exception('Failed to include "'.$filename.'"');
		}
		if (!interface_exists($interface)) {
			throw new Exception('A autoloader file is corrupt, interface "'.$interface.'" not found in "'.$filename.'"');
		}
		return true;
	}

	/**
	 * Extract class and interface definitions from modules
	 *
	 * @param array $modules Array containing modules, compatible with SledgeHammer::getModules()
	 */
	function inspectModules($modules) {
		$memory_limit = NULL;
		$time_limit = NULL;
		$summary = array();
		$class_total = 0;
		$interfaces_total = 0;
		foreach ($modules as $module_name => $module) {
			$revalidate_cache_delay = false;
			$folder = $module['path']; // De map waar mogelijk classes en interfaces bestand in staan.
			if (is_dir($folder.'classes')) {
				$folder .= 'classes'; // De module heeft een aparte map voor classes, negeer de andere mappen
				$settings = $this->strictSettings;
			} else {
				$settings = $this->defaultSettings;
			}
			if (is_dir($folder)) {
				if ($this->enableCache) {
					if (strpos($module['path'], $this->path) === 0) { // Staat de module bij deze app
						$cache_file = $this->cachePath.$module_name.'.php';
					} else { // De modules staan in een ander path (modules die via een devutils ingeladen worden)
						$cache_file = $this->cachePath.substr(md5(dirname($module['path'])), 8, 16).'/'.$module_name.'.php'; // md5(module_path)/module_naam(bv core).php
					}
					if (!mkdirs(dirname($cache_file))) {
						$this->enableCache = false;
					} else {
						if (file_exists($cache_file)) {
							$mtime_cache_file = filemtime($cache_file);
							// revalidate_cache_delay setting bepalen
							if (file_exists($folder.'/autoloader.ini')) {
								$autoloader_ini = parse_ini_file($folder.'/autoloader.ini', true);
								$revalidate_cache_delay = isset($autoloader_ini['revalidate_cache_delay']) ? $autoloader_ini['revalidate_cache_delay'] : $this->defaultSettings['revalidate_cache_delay'];
							} else {
								$revalidate_cache_delay = $this->defaultSettings['revalidate_cache_delay'];
							}
							$revalidate_cache = true;
							$now = time();
							if ($revalidate_cache_delay && $mtime_cache_file > ($now - $revalidate_cache_delay)) { // Is er een delay ingesteld en is deze nog niet verstreken?
								$revalidate_cache = false; // De mappen nog niet controleren op wijzigingen
							}
							if ($revalidate_cache == false || $mtime_cache_file > mtime_folders($folder)) { // Is het cache bestand niet verouderd?
								// Gebruik dan de cache
								include($cache_file);
								$this->classes = array_merge($this->classes, $classes);
								$this->interfaces = array_merge($this->interfaces, $interfaces);
								if ($revalidate_cache_delay && $revalidate_cache) { // is het cache bestand opnieuw gevalideerd?
									touch($cache_file); // de mtime van het cache-bestand aanpassen, (voor het bepalen of de delay is vertreken)
								}
								continue;
							}
						}
						$classes_before_extract = $this->classes;
						$interfaces_before_extract = $this->interfaces;
						$this->extractErrors = false; // reset de extract errors
					}
				}
				if ($memory_limit === NULL) { // is de memory_limit nog niet aangepast?
					$memory_limit = ini_get('memory_limit');
					ini_set('memory_limit', '128M'); // Tijdelijk de memory_limit verhogen. (De tokenizer gebruikt veel geheugen bij grote libraries)
					$time_limit = ini_get('max_execution_time');
					if ($time_limit == 0) { // Is er geen time_limit?
						$time_limit = NULL;
					} else {
						set_time_limit(120); // Tijdelijk de script timeout verhogen
					}
				}
				$this->inspectFolder($folder, $settings);
				// Append summary
				$class_subtotal = count($this->classes);
				$interfaces_subtotal = count($this->interfaces);
				$summary[$module['name']] = array(
					'classes' => $class_subtotal - $class_total,
					'interfaces' => $interfaces_subtotal - $interfaces_total
				);
				$class_total = $class_subtotal;
				$interfaces_total = $interfaces_subtotal;
				if ($this->enableCache && $this->extractErrors == false) { // Zijn er geen fouten opgetreden
					// De zojuist toegevoegde classes opslaan in een cache bestand
					$new_classes = array_diff_key($this->classes, $classes_before_extract); // nieuw ingelezen classes opvragen.
					$new_interfaces = array_diff_key($this->interfaces, $interfaces_before_extract); // nieuw ingelezen interfaces opvragen.
					$this->writeCacheFile($cache_file, $new_classes, $new_interfaces);
				}
			}
		}
		if ($memory_limit !== NULL) { // Is de memory_limit aangepast
			ini_set('memory_limit', $memory_limit); // Herstel de memory limit
		}
		if ($time_limit !== NULL) { // Is de memory_limit aangepast
			$time_limit = round($time_limit + (time() - MICROTIME_START)); // Tel de elapsed time op bij de timeout.
			set_time_limit($time_limit); // Herstel de timeout
		}
		return $summary;
	}

	/**
	 * Extract class and interface definitions from a directory.
	 *
	 * @return void
	 */
	function inspectFolder($folder, $settings = NULL) {
		if ($settings === NULL) {
			$settings = $this->defaultSettings;
		}
		if (in_array(basename($folder), explode(',', $settings['ignore_folders']))) {
			return;
		}
		if (file_exists($folder.'/library.ini')) {
			deprecated('Rename "library.ini" to "autoloader.ini"');
		}
		if (file_exists($folder.'/autoloader.ini')) {
			$autoloader_ini = parse_ini_file($folder.'/autoloader.ini', true);
			foreach ($autoloader_ini as $class => $info) {
				if (is_array($info)) { // Is het geen autoloader instelling maar een class definitie?
					$info['filename'] = $this->relativePath($folder.DIRECTORY_SEPARATOR.$info['filename']);
					$this->classes[$class] = $info;
				} elseif (!in_array($class, array_keys($this->defaultSettings))) {
					$this->parserNotice('Invalid setting: "'.$class.'" = '.syntax_highlight($info).' in "'.$this->relativePath($folder).'/autoloader.ini"', array('Available settings' => array_keys($this->defaultSettings)));
				}
			}
			$settings = array_merge($settings, $autoloader_ini); // De settings aanpassen aan wat in de autoloader.ini staat
			if ($settings['ignore_all']) {
				return;
			}
		}
		$DirectoryIterator = new \DirectoryIterator($folder);
		$ignoreFiles = explode(',', $settings['ignore_files']);
		$ignoreFiles[] =  'autoloader.ini';
		foreach ($DirectoryIterator as $Entry) {
			$filename = $Entry->getFilename();
			if ($Entry->isDir()) {
				if (substr($filename, 0, 1) != '.') { // Mappen die beginnen met een punt negeren. ("..", ".svn", enz)
					$this->inspectFolder($Entry->getPathname(), $settings);
				}
				continue;
			}

			if (in_array($filename, $ignoreFiles)) {
				continue;
			}
			if (substr($filename, -4) != '.php') {
				if ($settings['ignore_extensions'] === true) {
					continue;
				}
				$extension = file_extension($filename);
				$extension_whilelist = explode(',', $settings['ignore_extensions']);
				if (!in_array($extension, $extension_whilelist)) {
					$this->parserNotice('Unexpected extension for "'.$Entry->getPathname().'", expecting ".php"');
				}
				continue;
			}
			$definitions = $this->inspectFile($Entry->getPathname(), $settings);
			if ($definitions) {
				foreach ($definitions['classes'] as $definition) {
					$classPrefix = '';
					if ($definition['namespace'] != '') {
						$classPrefix = $definition['namespace'].'\\';
						// @todo extends ook de prefix geven
					}
					$class = $classPrefix.$definition['class'];
					unset($definition['class']);
					unset($definition['namespace']);
					if (isset($this->classes[$class])) {
						if ($this->classes[$class]['filename'] != $definition['filename']) {
							$this->parserNotice('Class: "'.$class.'" is ambiguous, it\'s found in multiple files: "'.$this->classes[$class]['filename'].'" and "'.$definition['filename'].'"');
						} elseif ($settings['notify_on_multiple_definitions_per_file']) {
							$this->parserNotice('Class: "'.$class.'" is declared multiple times in: "'.$definition['filename'].'"');
						}
					}
					$this->classes[$class] = $definition;
				}
				foreach ($definitions['interfaces'] as $definition) {
					$interface = $definition['interface'];
					if (isset($this->interfaces[$interface])) {
						$this->parserNotice('Interface: "'.$interface.'" is ambiguous, it\'s found in multiple files: "'.$this->interfaces[$interface].'" and "'.$definition['filename'].'"');
					}
					$this->interfaces[$interface] = array('filename' => $definition['filename']);
					if (isset($definition['extends'])) {
						$this->interfaces[$interface]['extends'] = $definition['extends'];
					}
				}
			}
		}
	}

	/**
	 * Check for invalid option in the $this->classes and $this->interface array
	 *
	 * @return bool Return true if no errors where found
	 */
	function validate() {
		$no_errors_found = true;
		foreach ($this->classes as $class => $info) {
			// check parent class
			if (isset($info['extends'])) {
				if (!isset($this->classes[$info['extends']]) && !class_exists($info['extends'], false)) {
					notice('Parent class "'.$info['extends'].'" not found for class "'.$class.'" in '.$info['filename']);
					$no_errors_found = false;
				}
			}
			if (isset($info['implements'])) {
				foreach ($info['implements'] as $interface) {
					if (!isset($this->interfaces[$interface]) && !interface_exists($interface, false)) {
						notice('Interface "'.$interface.'" not found for class "'.$class.'" in '.$info['filename']);
						$no_errors_found = false;
					}
				}
			}
		}
		return $no_errors_found;
	}

	/**
	 * Schrijf een cache bestand voor de autoloader class in de PATH map.
	 * Deze zal bij de init() worden ingeladen, de extract_definitions_* functies worden dan niet meer gebruikt, waardoor de parsetijd korter is.
	 */
	function saveDatabase() {
		return $this->writeCacheFile($this->path.'autoloader.db.php', $this->classes, $this->interfaces);
	}

	/**
	 * Een cache bestand wegschrijven
	 */
	private function writeCacheFile($filename, $classes, $interfaces) {
		$fp = fopen($filename, 'w');
		if ($fp == false) {
			return false;
		}
		fputs($fp, "<?php\n/**\n * Generated autoloader-definitions-cache\n */\n\$classes = array(\n");
		// classes
		ksort($classes);
		foreach ($classes as $class => $info) {
			fputs($fp, "\t'".$class."'=> array('filename'=>'".addslashes($info['filename'])."'");
			if (isset($info['extends'])) {
				fputs($fp, ",'extends'=>'".addslashes($info['extends'])."'");
			}
			if (isset($info['implements'])) {
				fputs($fp, ",'implements'=>array('".implode("','", $info['implements'])."')");
			}
			fputs($fp, "),\n");
		}
		// interfaces
		ksort($interfaces);
		fputs($fp, ");\n\$interfaces = array(\n");
		foreach ($interfaces as $interface => $info) {
			fputs($fp, "\t'".$interface."'=>array('filename'=>'".addslashes($info['filename'])."'");
			if (isset($info['extends'])) {
				fputs($fp, ",'extends'=>array('".implode("','", $info['extends'])."')");
			}
			fputs($fp, "),\n");
		}
		fputs($fp, ");\n?>");
		fclose($fp);
		return true;
	}

	/**
	 * Broncode van een php bestand/class laten zien
	 */
	function highlightClass($class) {
		$info = $this->getInfo($class);
		if ($info) {
			highlight_file($this->path.$info['filename']);
		}
	}

	/**
	 * Gegevens van een class opvragen
	 *
	 * @return array
	 */
	function getInfo($class, $allow_unknown_class = false) {
		$info = @$this->classes[$class];
		if ($info !== NULL) {
			if (is_string($info)) {
				return array(
					'filename' => $this->fullpath($info)
				);
			} else {
				$info['filename'] = $this->fullpath($info['filename']);
			}
			return $info;
		}
		if (!$allow_unknown_class) {
			notice('Class: "'.$class.'" is unknown', array('Known classes' => implode(', ', array_keys($this->classes))));
		}
		return false;
	}

	/**
	 * Veranderdt de scope van alle functies en eigenschappen van de $class van private of protected naar public.
	 * Hierdoor kun je in UnitTests controleren of de inhoud van het objecten correct is.
	 * Deze functie NIET gebruiken in productie-code
	 *
	 * @throws Exception Als de class al gedefineerd is. (De eval() zou anders fatale "Cannot redeclare class" veroorzaken).
	 * @return void
	 */
	function exposePrivates($class) {
		if (class_exists($class, false)) {
			throw new Exception('Class: "'.$class.'" is already defined');
		}
		$info = $this->getInfo($class);
		$tokens = token_get_all(file_get_contents($info['filename']));
		$php_code = '';
		if ($tokens[0][0] != T_OPEN_TAG) {
			notice('Unexpected beginning of the file, expecting "<?php"');
			return;
		} else {
			unset($tokens[0]); // Haal de "<?php" er vanaf
		}
		foreach ($tokens as $token) {
			if (is_string($token)) {
				$php_code .= $token;
				continue;
			}
			switch ($token[0]) {

				// Geen commentaar
				case T_DOC_COMMENT:
				case T_COMMENT:
					break;
				// Geen private en protected
				case T_PRIVATE:
				case T_PROTECTED:
					$php_code .= 'public';
					break;

				// Alle andere php_code toevoegen aan de $php_code string
				default:
					$php_code .= $token[1];
					break;
			}
		}
		// De php code uitvoeren en de class (zonder protected en private) declareren
		eval($php_code);
	}

	/**
	 * Extract class and interface definitions from a file.
	 *
	 * @param string $filename Fullpath to the php-file.
	 * @return array Array containing definitions
	 */
	private function inspectFile($filename, $settings) {
		$source = file_get_contents($filename);
		/*
		 * @todo Instead of an eof check,  check if there is any output(non phpcode) in hthe script
		  if ($settings['eof_check'] && substr($source, -3, -1) != '?>' && substr($source, -2) != '?>') {
		  $this->parserNotice('Invalid end of file: "'.$this->relativePath($filename).'", expecting "?>"');
		  } */
		if ($settings['mandatory_comment_block'] && substr($source, 0, 12) != "<?php\n/**\n *" && substr($source, 0, 14) != "<?php\r\n/**\r\n *") {
			$this->parserNotice('Invalid start of file:  "'.$this->relativePath($filename).'"', array('Expecting' => "\"\n<?php\n/**\n *\""));
		}
		$tokens = token_get_all($source);
		$statusses_definitions = array();
		$index = 0;
		$namespace_state = 'GLOBAL';
		$namespace = '';
		foreach ($tokens as $token) {
			if (empty($statusses_definitions[$index])) {
				$statusses_definitions[$index] = array(
					'class' => false,
					'extends' => array(),
					'implements' => false,
					'interface' => false,
					'info' => array(
						'filename' => $this->relativePath($filename),
						'namespace' => $namespace,
					),
				);
				$definition_state = &$statusses_definitions[$index];
			}
			if ($token[0] == T_COMMENT) { // Is het commentaar
				continue; // negeer deze token
			}
			if ($token[0] == T_NAMESPACE) {
				$namespace_state = 'NEXT_STRING';
				$namespace = ''; //reset namespace
				continue;
			}
			// Gaat het om een class of een interface?
			if ($token[0] == T_CLASS) { // Is er een "class" van de class definitie gevonden?
				$definition_state['class'] = 'NEXT_STRING'; // aangeven zodat de naam van de class achterhaald kan worden.
				continue;
			}
			if ($token[0] == T_INTERFACE) { // Is er een "interface" van een interface definitie gevonden?
				$definition_state['interface'] = 'NEXT_STRING';
				continue;
			}
			if ($token[0] == T_EXTENDS) {
				$definition_state['extends'] = 'NEXT_STRING';
				continue;
			}
			if ($namespace_state == 'NEXT_STRING') {
				if ($token == ';') {
					$definition_state['info']['namespace'] = $namespace;
					$namespace_state = 'SET';
					continue;
				}
				if ($token[0] == T_WHITESPACE) {
					continue;
				}
				if ($token[0] == T_NS_SEPARATOR) {
					$namespace .= $token[1];
					continue;
				}
				if ($token[0] == T_STRING) {
					$namespace .= $token[1];
					continue;
				} else {
					$this->unexpectedToken($token, $filename);
				}
			}

			if ($definition_state['extends'] == 'NEXT_STRING' && $token[0] == T_STRING) {
				$definition_state['info']['extends'][] = $token[1];
				$definition_state['extends'] = 'FOUND';
				continue;
			}
			if ($definition_state['interface'] == 'FOUND' && $definition_state['extends'] == 'FOUND' && $token == ',') { // Heeft deze interface van meer dan 1 extends ?
				$definition_state['extends'] = 'NEXT_STRING';
				continue;
			}
			if ($definition_state['class'] == 'NEXT_STRING' || $definition_state['class'] == 'FOUND') {
				if ($token == '{') { // Is de '{' gevonden?
					$definition_state['class'] = 'DEFINED';
					$index++;
					continue;
				}
				if ($definition_state['implements'] == 'NEXT_STRINGS' && $token == ',') {
					continue;
				}
				if ($token[0] == T_WHITESPACE) {
					continue;
				}
				if ($token[0] == T_IMPLEMENTS) {
					$definition_state['implements'] = 'NEXT_STRINGS';
					continue;
				}
				if ($token[0] == T_STRING) {
					if ($definition_state['class'] == 'NEXT_STRING') {
						$definition_state['info']['class'] = $token[1];
						$definition_state['class'] = 'FOUND';
					} elseif ($definition_state['implements'] == 'NEXT_STRINGS') {
						$definition_state['info']['implements'][] = $token[1];
					} else {
						$this->unexpectedToken($token, $filename);
					}
				} else {
					$this->unexpectedToken($token, $filename);
				}
			}
			if ($definition_state['interface'] == 'NEXT_STRING' || $definition_state['interface'] == 'FOUND') {
				if ($token == '{') { // Is de '{' gevonden?
					$definition_state['interface'] = 'DEFINED';
					continue;
				}
				if ($token[0] == T_WHITESPACE) {
					continue;
				} elseif ($token[0] == T_NS_SEPARATOR) {
					$definition_state['info']['interface'] .= $token[1];
					continue;
				} elseif ($token[0] == T_STRING) {
					if ($definition_state['interface'] == 'NEXT_STRING') {
						$definition_state['info']['interface'] .= $token[1];
						$definition_state['interface'] = 'FOUND';
					} elseif ($definition_state['extends'] == 'NEXT_STRING') {

					} else {
						$this->unexpectedToken($token, $filename);
					}
				} else {
					$this->unexpectedToken($token, $filename);
				}
			}
		}

		// De file is geparsed, en de definities zijn bekend
		if (count($statusses_definitions) > 1 && $statusses_definitions[$index]['class'] == false && $statusses_definitions[$index]['interface'] == false) {
			unset($statusses_definitions[$index]);
		}
		$definitions = array(
			'classes' => array(),
			'interfaces' => array(),
		);
		foreach ($statusses_definitions as $definition_state) {
			if ($definition_state['interface'] == 'DEFINED') {
				$definitions['interfaces'][] = $definition_state['info'];
			} elseif ($definition_state['class'] == 'DEFINED') {
				if ($definition_state['extends'] == 'FOUND') { // Heeft de class een superclass?
					if (count($definition_state['info']['extends']) == 1) {
						$definition_state['info']['extends'] = $definition_state['info']['extends'][0];
					} elseif (count($definition_state['info']['extends']) > 1) {
						$this->parserNotice('Class: "'.$definition_state['info']['class'].'" Multiple inheritance is not allowed for classes');
					} else {
						$this->parserNotice('Huh??');
						$definition_state['info']['extends'] = false;
					}
					if ($definition_state['info']['extends'] == 'SledgeHammer\Object') {
						unset($definition_state['info']['extends']);
					}
				} elseif ($settings['mandatory_superclass'] && !in_array($definition_state['info']['class'], array('Object', 'Framework', 'ErrorHandler'))) {
					$this->parserNotice('Class: "'.$definition_state['info']['class'].'" has no superclass, "class X extends Y" expected');
				}
				if ($settings['matching_filename'] && substr(basename($filename), 0, -4) != $definition_state['info']['class']) {
					$this->parserNotice('Filename doesn\'t match classname "'.$definition_state['info']['class'].'" in "'.$this->relativePath($filename).'"');
				}
				$definitions['classes'][] = $definition_state['info'];
			} else {

			}
		}
		$definition_count = count($statusses_definitions);
		if ($definition_count > 1) {
			if ($settings['notify_on_multiple_definitions_per_file']) {
				$this->parserNotice('Multiple definitions per file is not recommended', array_merge($definitions['classes'], $definitions['interfaces']));
			}
		} elseif ($definition_count == 0) {
			$this->parserNotice('No classes or interfaces found in '.$filename);
		}
		return $definitions;
	}

	private function unexpectedToken($token, $filename) {
		if (is_string($token)) {
			$error = syntax_highlight($token);
		} else {
			$error = token_name($token[0]).': '.syntax_highlight($token[1]);
		}
		$this->parserNotice('Unexpected token: '.$error.' in "'.$this->relativePath($filename).'"');
	}

	/**
	 * Naast het geven van een notice zal de $this->extractErrors op true gezet worden
	 *
	 * @see notice()
	 * @return void
	 */
	private function parserNotice($message, $extra_information = NULL) {
		notice($message, $extra_information);
		$this->extractErrors = true;
	}

	/**
	 * Maakt van een absoluut path een relatief path (waar mogelijk)
	 *
	 * @param $filename Absoluut path
	 */
	private function relativePath($filename) {
		if (strpos($filename, $this->path) === 0) {
			$filename = substr($filename, strlen($this->path));
		}
		return $filename;
	}

	/**
	 * Geeft aan een absoluut path terug voor $filename
	 *
	 * @param string $filename relatief of absoluut path van het bestand
	 */
	private function fullpath($filename) {
		if (DIRECTORY_SEPARATOR == '/') {// Gaat het om een unix bestandsysteem
			// Bij een unix variant begint een absoluut path met  '/'
			if (substr($filename, 0, 1) == '/') {
				return $filename; // Absoluut path
			}
		} elseif (preg_match('/^[a-z]{1}:/i', $filename)) { //  Een windows absoluut path begint met driveletter. bv 'C:\'
			return $filename; // Absoluut path
		}
		// Anders was het een relatief path
		return $this->path.$filename;
	}

}

?>
