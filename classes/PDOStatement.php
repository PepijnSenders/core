<?php
/**
 * PDOStatement override
 * Adds Countable to the Database results.
 *
 * @package Core
 */
namespace SledgeHammer;

class PDOStatement extends \PDOStatement implements \Countable {

	/**
	 * Return the number of rows in the result
	 * (Slow on SQlite databases)
	 * @return int
	 */
	public function count() {
		$count = $this->rowCount();
		if ($count !== 0) {
			return $count; // Return the rowCount (num_rows in MySQL)
		}
		// SQLite returns 0 (no affected rows)
		return count($this->fetchAll());
	}

}

?>
