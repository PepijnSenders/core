<?php
/**
 * SledgeHammer Core initialiseren
 *
 * @package Core
 */
namespace SledgeHammer;
if (!defined('SledgeHammer\MICROTIME_START')) {
	define('SledgeHammer\MICROTIME_START', microtime(true));
}
define('SledgeHammer\MODULES_DIR', dirname(dirname(__FILE__)).DIRECTORY_SEPARATOR); // Het pad instellen. Dit is de map waar de sledgehammer map in staat.
define('SledgeHammer\PATH', dirname(MODULES_DIR).DIRECTORY_SEPARATOR); // Het pad instellen. Dit is de map waar de sledgehammer map in staat.
define('SledgeHammer\APPLICATION_DIR', PATH.'application'.DIRECTORY_SEPARATOR);
define('SledgeHammer\E_MAX', (E_ALL | E_STRICT)); /// Echt alle errors afvangen, inclusief de PHP5 STRICT hints
error_reporting(E_MAX); // Foutniveau activeren
if (ini_get('date.timezone') == '') { // Is er geen tijdzone ingesteld?
	date_default_timezone_set('Europe/Amsterdam'); // Voorkom foutmeldingen door de tijdzone in te stellen
}
$coreDir = dirname(__FILE__).'/';
require_once($coreDir.'functions.php'); 
require_once($coreDir.'classes/Object.php'); // De generieke superclass
require_once($coreDir.'classes/Framework.php'); // Helper class voor modules e.d. 
require($coreDir.'classes/ErrorHandler.php');
require($coreDir.'classes/AutoLoader.php');

$GLOBALS['charset'] = 'UTF-8';
if (function_exists('mb_internal_encoding')) {
	mb_internal_encoding($GLOBALS['charset']);
}

// Detect a writable tmp folder
if (defined('SledgeHammer\TMP_DIR')) {
	mkdirs(TMP_DIR);
} else {
	$tmpDir = PATH.'tmp'.DIRECTORY_SEPARATOR;
	if (is_dir($tmpDir) && is_writable($tmpDir)) {  // Use the project tmp folder?
		define('SledgeHammer\TMP_DIR', $tmpDir);
	} else {
		$tmpDir = '/tmp/sledgehammer-'.md5(PATH).'/';
		if (function_exists('posix_getpwuid')) {
			$tmpDir .= array_value(posix_getpwuid(posix_geteuid()), 'name').'/';
		}
		define('SledgeHammer\TMP_DIR', $tmpDir);
		mkdirs(TMP_DIR);
	}
}

// ErrorHandeler instellen (standaard configuratie: geeft geen output, maar logt deze naar de error_log())
$GLOBALS['ErrorHandler'] = new ErrorHandler;
$GLOBALS['ErrorHandler']->init();

$GLOBALS['AutoLoader'] = new AutoLoader(PATH); // De AutoLoader aanmaken. (maar om te functioneren moet de $AutoLoader->init() nog aangeroepen worden)
spl_autoload_register(array($GLOBALS['AutoLoader'], 'define'));

unset($coreDir, $tmpDir);
?>
