<?php
/**
 * Verantwoordelijk voor het on-the-fly inladen en declareren van classes en interfaces.
 * Dit verbeterd parsetijd/geheugenverbruik aanzienlijk, alleen de bestanden die je nodig hebt worden ge-include.
 *
 * @package Core
 */
namespace SledgeHammer;
class PHPAnalyzer extends Object {
	
	public $classes = array();
	
	public $interfaces = array();

	/**
	 * Extract class and interface definitions from a file.
	 *
	 * @param string $filename Fullpath to the php-file.
	 */
	function open($filename) {
		$tokens = new PHPTokenizer(file_get_contents($filename));
		unset($source);
		
		$namespace = '';
		$uses = array();
		$definitions = array();
		$definition = array('level' => -1);
		$globalFunctions =array();
		$functions = &$globalFunctions;
		$level = 0;
		foreach ($tokens as $token) {
			$type = $token[0];
			$value = $token[1];
			if ($value == '') {
				notice('Empty token', $token);
//				dump(iterator_to_array($tokens));
//				die;
			}
			if ($type == 'T_PHP' || $type == 'T_HTML') {
				continue;
			}
			switch ($type) {
				
				case 'T_NAMESPACE':
					$namespace = $value;
					break;
				
				case 'T_USE':
					$pos = strrpos($value, '\\');
					$namespaceAlias = substr($value, $pos + 1);
					$uses[$namespaceAlias] = $value;
					break;
					
				case 'T_USE_AS':
					$uses[$value] = $uses[$namespaceAlias];
					unset($uses[$namespaceAlias]);
					break;
					
				
				case 'T_INTERFACE':
					$definitions[] = array(
						'type' => 'INTERFACE',
						'namespace' => $namespace,
						'interface' => $value,
						'identifier' => $this->prefixNamespace($namespace, $value, $uses),
						'extends' => array(),
						'methods' => array(),
						'level' => $level
					);
					$definition = &$definitions[count($definitions) - 1];
					break;
				
				case 'T_CLASS':
					$definitions[] = array(
						'type' => 'CLASS',
						'namespace' => $namespace, 
						'class' => $value,
						'identifier' => $this->prefixNamespace($namespace, $value, $uses),
						'extends' => array(),
						'implements' => array(),
						'methods' => array(),
						'level' => $level
						
					);
					$definition = &$definitions[count($definitions) - 1];
					break;
				
				case 'T_EXTENDS':
					$definition['extends'][] = $this->prefixNamespace($namespace, $value, $uses);
					break;
				
				case 'T_IMPLEMENTS':
					$definition['implements'][] = $this->prefixNamespace($namespace, $value, $uses);
					break;
				
				case 'T_FUNCTION':
					$function = $value;
					$parameter = null;
					if ($level == ($definition['level'] + 1)) {
						$definition['methods'][$function] = array();
						$functions = &$definition['methods'];
					} else {
						$functions = &$globalFunctions;
					}
					break;
				
				case 'T_PARAMETER':
					$parameter = substr($value, strpos($value, '$') + 1);
					$functions[$function][$parameter] = null;
					break;
				
				case 'T_PARAMETER_VALUE':
					$functions[$function][$parameter] = $value;
					$parameter = null;
					break;
				
				case 'T_OPEN_BRACKET':
					$level++;
					break;
				
				case 'T_CLOSE_BRACKET':
					
					$level--;
					break;

				default:
					notice('Unexpected tokenType: "'.$type.'"');
					break;
			}
		}
		if ($level != 0) {
			notice('Level: '.$level.' Number of "{" doesn\'t match the number of "}"');
		}
		unset($definition);
		// Add definitions to de loader
		foreach ($definitions as $index => $definition) {
			$identifier = $definition['identifier'];
			unset($definition['identifier'], $definition['level']);
			$definition['filename'] = $filename;
			/*
			$duplicate = false;
			if (isset($this->classes[$identifier])) {
				$duplicate = $this->classes[$identifier];
			} elseif (isset($this->interfaces[$identifier])) {
				$duplicate = $this->interfaces[$identifier];
			}
			if ($duplicate) {
				$this->parserNotice('"'.$identifier.'" is ambiguous, it\'s found in multiple files: "'.$duplicate['filename'].'" and "'.$definition['filename'].'"');
			}*/
			switch ($definition['type']) {
					
				case 'CLASS':
					unset($definition['type']);
					if (count($definition['extends']) > 1) {
						notice('Class: "'.$definition['class'].'" Multiple inheritance is not allowed for classes');
						$definition['extends'] = $definition['extends'][0];
					} elseif (count($definition['extends']) == 1) {
						$definition['extends'] = $definition['extends'][0];
					} else {
						unset($definition['extends']);
					}
					if (count($definition['implements']) == 0) {
						unset($definition['implements']);
					}
					$this->classes[$identifier] = $definition;
					break;

				case 'INTERFACE':
					unset($definition['type']);
					$this->interfaces[$identifier] = $definition;
					break;

				default:
					throw new \Exception('Unsupported type: "'.$definition['type'].'"');
			}
		}
	}
	
	function getInfo($definition) {
		// Check analyzed definitions
		if (isset($this->classes[$definition])) {
			return $this->classes[$definition];
		}
		if (isset($this->interfaces[$definition])) {
			return $this->interfaces[$definition];
		}
		$filename = $GLOBALS['AutoLoader']->getFilename($definition);
		if ($filename !== null) {
			$this->open($filename);
		} elseif (class_exists($definition, false) || interface_exists($definition, false)) {
			$this->getInfoWithReflection($definition);
		}
		if (isset($this->classes[$definition])) {
			return $this->classes[$definition];
		}
		if (isset($this->interfaces[$definition])) {
			return $this->interfaces[$definition];
		}
		throw new \Exception('Definition "'.$definition.'" is not found');

	}
	function getInfoWithReflection($definition) {
		if (class_exists($definition) == false && interface_exists($definition) == false) {
			//throw new \Exception('Definition "'.$definition.'" is unknown');
		}
		$reflectionClass = new \ReflectionClass($definition);
		$info = array(
			'namespace' => $reflectionClass->getNamespaceName()
		);
		$class = $reflectionClass->name;
		if ($reflectionClass->isInterface()) {
			$info['interface'] = $class;
			$info['extends'] = $reflectionClass->getInterfaceNames();
		} else {
			$info['class'] = $class;
			$info['implements'] = $reflectionClass->getInterfaceNames();
			$info['extends'] = $reflectionClass->getParentClass();
			if ($info['extends'] == false || $info['extends']->name == 'SledgeHammer\Object' || $info['extends']->name == 'stdClass') {
				unset($info['extends']);
			} else {
				$info['extends'] = $info['extends']->name;
			}
		}
		$info['methods'] = array();
		foreach ($reflectionClass->getMethods() as $reflectionMethod) {
			if ($reflectionMethod->class != $class) {
				continue; // De methoden uit de parentclass negeren
			}
			$method = $reflectionMethod->name;
			$info['methods'][$method] = array();
			foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
				$parameter = $reflectionParameter->name;
				$info['methods'][$method][$parameter] = null;
				if ($reflectionParameter->isDefaultValueAvailable()) {
					$value =  $reflectionParameter->getDefaultValue();
					$info['methods'][$method][$parameter] = $value;
				}
			}
		}
		if ($reflectionClass->isInterface()) {
			$this->interfaces[$definition] = $info;
		} else {
			$this->classes[$definition] = $info;
		}
		return $info;
	}


	/**
	 * Resolve the full classname.
	 * 
	 * @param string $namespace
	 * @param string $identifier  The class or interface name 
	 * @return string 
	 */
	private function prefixNamespace($namespace, $identifier, $uses = array()) {
		$pos = strpos($identifier, '\\');
		if ($pos !== false) {
			if ($pos === 0) {
				return substr($identifier, 1);
			}
			foreach ($uses as $alias => $namespace) {
				$alias .= '\\'; 
				if (substr($identifier, 0, strlen($alias)) === $alias) {
					return $namespace.substr($identifier, strlen($alias) - 1);
				}
			}
			return $identifier;
		}
		if (isset($uses[$identifier])) {
			return $uses[$identifier];
		}
		if ($namespace == '') {
			return $identifier;
		}
		return $namespace.'\\'.$identifier;
	}
/*
	private function unexpectedToken($token, $filename) {
		if (is_string($token)) {
			$error = syntax_highlight($token);
		} else {
			$error = token_name($token[0]).': '.syntax_highlight($token[1]);
		}
		notice('Unexpected token: '.$error.' in "'.$this->relativePath($filename).'"');
	}
 */
}
?>
