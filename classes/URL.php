<?php
/**
 * URL class for generating and manipulating urls.
 *
 * @package Core
 */
class URL extends Object {
	
	/**
	 * @var string The protocol schema
	 */
	public $scheme;
	/**
	 * @var string The hostname/ip
	 */
	public $host;
	/**
	 * @var int Portnumber
	 */
	public $port;
	/**
	 * @var string The (unescaped) path
	 */
	public $path;
	/**
	 * @var array The parameters in the querystring
	 */
	public $query = array();
	
	public
		$user,
		$pass,
		$fragment;
	
	/**
	 * @param NULL|string $url De url om te parsen, bij NULL wordt de huidige url gebruikt
	 */
	function __construct($url) {
		$info = parse_url($url);
		if ($info === false) {
			throw new Exception('Invalid url: "'.$url.'"');
		}
		if (isset($info['query'])) {
			 parse_str($info['query'], $info['query']); // Zet de query om naar een array
		}
		if (isset($info['path'])) {
			$info['path'] = rawurldecode($info['path']); // "%20" omzetten naar " " e.d.
		}
		set_object_vars($this, $info);
	}
	
	/**
	 * Generate the url as a string.
	 * 
	 * @return string 
	 */
	function __toString() {
		$url = '';
		if ($this->scheme !== null && $this->host !== null) {
			$url .= $this->scheme.'://';
			if (empty($this->user) == false) {
				$url .= $this->user;
				if (empty($this->pass) == false) {
					$url .= ':'.$this->pass;
				}
				$url .= '@';
			}			
			$url .= $this->host;
			if ($this->port) {
				$url .= ':'.$this->port;
			}
		}
		if ($this->path !== null) {
			$url .= str_replace('%2F', '/', rawurlencode($this->path));
		}
		if (is_string($this->query)) {
			$url .= '?'.$this->query;
		} elseif (count($this->query) != 0) {
			$url .= '?'.http_build_query($this->query);
		}
		if ($this->fragment !== null) {
			$url .= '#'.$this->fragment;
		}
		return $url;
	}
	
	/**
	 * Get foldes in a array (based on the path)
	 * @return array
	 */
	function getFolders() {
		$parts = explode('/', $this->path);
		array_pop($parts); // remove filename part
		$folders = array();
		foreach ($parts as $folder) {
			if ($folder !== '') { // dont add the root and skip "//" 
				$folders[] = $folder;
			}
		}
		return $folders;
	}
	
	/**
	 * Get de filename (or "index.html" if no filename is given.)
	 * @return string
	 */
	function getFilename() {
		if ($this->path === null || substr($this->path, -1) == '/') { // Gaat het om een map?
			return 'index.html';
		}
		return basename($this->path);
	}
	
	/**
	 * Gets the current url based on the information in $_SERVER
	 * @return URL
	 */
	static function getCurrentURL() {
		$url = 'http';
		if (array_value($_SERVER, 'HTTPS') == 'on') {
			$url .= 's';
		}
		$url .= '://';
		$url .= $_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
		return new URL($url);
	}

	/**
	 * Vraag een specifiek deel van url op of de lijst met onderdelen
	 *
	 * @param NULL|string $part Het deel van de url welke gevraagd wordt, bij NULL krijg je een array met alle gegevens
	 * @param NULL|string $url De url om te parsen, bij NULL wordt de huidige url gebruikt
	 * @return mixed
	 */
	static function info($part, $url = null) {
		deprecated('Use the OOP "new URL()" syntax');
		if ($part === null) {
			error('No longer supported');
		}
		$url = new URL($url);
		return $url->$part;
	}
	
	static function uri() {
		deprecated('Use the OOP "new URL()" syntax');
		$url = new URL();
		return $url->__toString();
	}
	
	static function extract_path() {
		deprecated('Use the OOP "new URL()" syntax');
		$url = URL::getCurrentURL();
		return array(
			'filename' => $url->getFilename(),
			'folders'  => $url->getFolders(),
		);
	}
	
	/**
	 * Multi-functionele functie om parameters op te vragen en toe te voegen
	 *
	 * URL:parameters(); vraagt de huidige parameters op. ($_GET)
	 * URL:parameters("naam['test']=1234"); of URL::parameters(array('naam'=>array('test'=>1234))); voegt deze parameter toe aan de huidige parameter array.
	 * URL:parameter("bla=true", 'x=y'); voegt 2 parameter 'arrays' samen
	 *
	 * @param array $append De parameter die toegevoegd moet worden
	 * @param mixed $stack: De url of array waarde parameters waaraan toegevoegd moet worden, bij NULL worden de huidige $_GET parameters gebruikt
	 * @return array
	 */
	static function parameters($append = array(), $stack = NULL) {
		deprecated('Maar nog geen alternatief beschikbaar');
		if ($stack === NULL) { // Huidige parameters opvragen
			$stack = $_GET;
		} elseif (is_string($stack)) { // Is er geen array, maar een query string meegegeven?
			parse_str($stack, $stack);
		}
		if (is_string($append)) {
			parse_str($append, $append);
		}
		return array_merge(array_diff_key($stack, $append), $append); // De array kan gebruikt worden in een http_build_query()
	}
	
		/**
	 * Een sub-domein opvragen van een domein
	 * 
	 * @param int $index Bepaald welke subdomein van de subdomeinen er wordt opgevraagd. 0 = eerste subdomein van links, -1 =  eerste subdomein van rechts
	 * @param NULL|string $uri de uri waarvan het subdomein opgevraagd moet worden
	 * @return string
	 */
	static function subdomain($index = -1, $uri = NULL) {
		deprecated('Maar nog geen alternatief beschikbaar');

		if ($uri === NULL) {
			$uri = URL::info('host');
		}
		$parts = explode('.', $uri);
		$count = count($parts);
		if ($index < 0) { // is $index negatief?
			$index = $count - 2 + $index; // van links naar rechts
		} elseif ($index + 2 >= $count) { // is $index groter dan aantal subdomeinen? 
			return '';
		}
		$subdomain = @$parts[$index];
		return ($subdomain === NULL) ? '' : $subdomain;
	}
	
	static function domain() {
		deprecated('Maar nog geen alternatief beschikbaar');

		$hostname = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : php_uname('n');
		$regexDomain = '/[a-z0-9]+([a-z]{2}){0,1}.[a-z]{2,4}$/i';
		if (preg_match($regexDomain, $hostname, $match)) { // Zit er een domeinnaam in de hostname? 
			return $match[0];
		}
		return 'example.com'; 	
	}
}
?>
