<?php
/**
 *
 */
namespace SledgeHammer;
return  array(
	'flush_tmp.html' => new FlushTemporaryFiles(),
	'generate_library.db.php' => new GenerateStaticLibrary(),
//	'populate_Docroot.html' => new UtilScript('populate_DocumentRoot.php', 'Generate static public/ folder'),
//	'compare_environments.html' => new CompareEnvironments,
);
?>
