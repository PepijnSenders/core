<?php
/**
 * DebugR
 */
namespace Sledgehammer;
/**
 * DebugR, send additional debugging information via HTTP heders.
 * @link http://debugr.net/
 *
 * @package Core
 */
class DebugR extends Object {

	static $increments = array();

	/**
	 * Monitor the number of bytes sent, to prevent sending more than 240KiB in DebugR headers. (Leaving 16KiB for normal headers)
	 * @link http://stackoverflow.com/questions/3326210/can-http-headers-be-too-big-for-browsers
	 * @var int
	 */
	static $bytesSent;

	/**
	 * Callback for sending a HTTP header.
	 * @var callable
	 */
	static $headerAdd = 'header';

	/**
	 * Callback for removing a HTTP header.
	 * @var callable
	 */
	static $headerRemove = 'header_remove';

	/**
	 * Send an variable to console.log()
	 * @param mixed $data
	 */
	static function log($variable) {
		return self::send('log', Json::encode($variable));
	}

	/**
	 * Send an message to console.warning()
	 * @param string $message  The warning message
	 */
	static function warning($message) {
		return self::send('warning', $message);
	}

	/**
	 * Send an message to console.error()
	 * @param string $message  The error message
	 */
	static function error($message) {
		return self::send('error', $message);
	}

	/**
	 * Send a dump() to the <body> element.
	 * @param string $data
	 */
	static function dump($variable) {
		$backtrace = debug_backtrace();
		$dump = new Dump($variable, $backtrace);
		ob_start();
		$dump->render();
		return self::html(ob_get_clean());
	}

	/**
	 * Send html prefixed with a DebugR header showning the request.
	 * @param string $html
	 */
	static function html($html) {
		$style = array(
			'display: inline-block',
			'padding: 8px 15px',
			'background-color: #7a3964',
			'background-image: -webkit-linear-gradient(-80deg,#7a3964,#2c1440)',
			'background-image: -moz-linear-gradient(-80deg,#7a3964,#2c1440)',
			'color: #fff',
			'font: bold 12px/1.25 \'Helvetica Neue\', Helvetica, sans-serif',
			'margin: 10px 5px -5px 5px',
			'border-radius: 4px',
			// reset styling
			'text-shadow: 0 -1px 0 black',
			'-webkit-font-smoothing: antialiased',
			'font-smoothing: antialiased',
			'text-align: left',
			'overflow-x: auto',
			'white-space: normal',
		);
		DebugR::send('html', '<div style="'.implode(';', $style).'"><span style="font-weight:normal;margin-right: 10px">DebugR</span>'.array_value($_SERVER, 'REQUEST_METHOD').'&nbsp;&nbsp;'.array_value($_SERVER, 'REQUEST_URI').'</div>'.$html);
	}

	/**
	 *
	 * @param string $label
	 * @param string $message
	 * @param string $overwrite
	 */
	static function send($label, $message, $overwrite = false) {
		if (self::isEnabled() === false) {
			return;
		}
		if (preg_match('/^(?<label>[a-z0-9\-]+)(?<suffix>\\.[0-9]+)?$/i', $label, $match) == false) {
			notice('Label: "'.$label.'" in invalid', 'A label may contain number, letters and "-"');
			return;
		}
		$number = array_value(self::$increments, $match['label']);
		if (isset($match['suffix'])) { // Has a suffix?
			$labelSuffix = $match[0];
			if ($overwrite === false) {
				notice('Overwrite flag required for label: "'.$label.'"');
				return;
			}
			if ($number <= substr($match['suffix'], 1)) {
				self::$increments[$match['label']] = substr($match['suffix'], 1) + 1;
			}
		} elseif ($overwrite === false) {
			if ($number) {
				$label .= '.'.$number;
			}
			self::$increments[$match['label']] = $number + 1;
		}
		if (headers_sent($file, $line)) {
			if ($file == '' && $line == 0) {
				$location = '';
			} else {
				$location = ', output started in '.$file.' on line '.$line;
			}
			notice('Couldn\'t sent header(s)'.$location);
			return;
		}
		$value = base64_encode($message);
		$length = strlen($value);
		// Prevent 325 net::ERR_RESPONSE_HEADERS_TOO_BIG in Google Chrome.
		if (self::$bytesSent + $length >= 240000) { // >= 235KiB?
			if (self::$bytesSent < 239950) {
				call_user_func(self::$headerAdd, 'DebugR-'.$label.': '.base64_encode('DebugR: TOO_MUCH_DATA'));
			}
			return;
		}

		if ($length <= (4 * 1024)) { // Under 4KiB?
			$header = 'DebugR-'.$label.': ';
			call_user_func(self::$headerAdd, $header.$value);
			self::$bytesSent += strlen($header) + $length;
		} else {
			// Send in 4KB chunks.
			call_user_func(self::$headerRemove, 'DebugR-'.$label);
			$chunks = str_split($value, (4 * 1024));
			foreach ($chunks as $index => $chunk) {
				$header = 'DebugR-'.$label.'.chunk'.$index.': ';
				call_user_func(self::$headerAdd, $header.$chunk);
				self::$bytesSent += strlen($header);
			}
			self::$bytesSent += $length;
		}
	}

	/**
	 * Check if DebugR is enabled.
	 * @return bool
	 */
	static function isEnabled() {
		// @todo Authentication
		return isset($_SERVER['HTTP_DEBUGR']);
	}

}

?>
