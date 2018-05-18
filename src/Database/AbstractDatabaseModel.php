<?php

namespace Catalyst\Database;

use \Catalyst\Database\Query\{SelectQuery, UpdateQuery};
use \Catalyst\Database\QueryAddition\WhereClause;
use \Catalyst\Images\{HasImageSetTrait, HasImageTrait};
use \BadMethodCallException;
use \Closure;
use \InvalidArgumentException;
use \Serializable;

/**
 * Used by database models that need to get stuff 
 */
abstract class AbstractDatabaseModel implements Serializable {
	/**
	 * The user's ID in the database
	 * @var int
	 */
	protected $id;

	/**
	 * This is used as not to repeatedly hammer the database
	 * @var array
	 */
	protected $cache = [];

	/**
	 * Creates a new object
	 *
	 * @param int $id
	 * @param array $cache Prefilled cache
	 */
	public function __construct(int $id, array $cache=[]) {
		if (!self::idExists($id)) {
			throw new InvalidArgumentException("ID ".$id." does not exist in table ".static::getTable().".");
		}
		$this->id = $id;
		$this->cache = $cache;
	}

	/**
	 * Check if a given ID exists in the database
	 * 
	 * @param int $id
	 * @return bool
	 */
	public static function idExists(int $id) : bool {
		$stmt = new SelectQuery();
		
		$stmt->setTable(static::getTable());
		$stmt->addColumn(new Column("ID", static::getTable()));

		$whereClause = new WhereClause();
		$whereClause->addToClause([new Column("ID", static::getTable()), "=", $id]);

		$stmt->addAdditionalCapability($whereClause);

		$stmt->execute();

		if (count($stmt->getResult()) == 0) {
			return false;
		}
		return true;
	}

	/**
	 * Get the table in which the object's data is stored in
	 * 
	 * @return string table name
	 */
	abstract public static function getTable() : string;

	/**
	 * Get the class' ID
	 * 
	 * @return int
	 */
	public function getId() : int {
		return $this->id;
	}

	/**
	 * Returns the column's value from the database
	 * 
	 * @param string $column Column to get
	 * @return mixed
	 */
	protected function getColumnFromDatabase(string $column) {
		$stmt = new SelectQuery();

		$stmt->setTable($this->getTable());
		$stmt->addColumn(new Column($column, $this->getTable()));

		$whereClause = new WhereClause();
		$whereClause->addToClause([new Column("ID", $this->getTable()), "=", $this->getId()]);

		$stmt->addAdditionalCapability($whereClause);

		$stmt->execute();

		return $stmt->getResult()[0][$column];
	}

	/**
	 * Returns the column's value from the database
	 * 
	 * @param string $column Column to get
	 * @return mixed
	 */
	protected function getColumnFromDatabaseOrCache(string $column) {
		if (array_key_exists($column, $this->cache)) {
			return $this->cache[$column];
		}
		return $this->cache[$column] = $this->getColumnFromDatabase($column);
	}

	/**
	 * Returns the key's value from cache, or stores value based on callable
	 * 
	 * @param string $key cache key
	 * @param callable $callable
	 * @return mixed
	 */
	protected function getDataFromCallableOrCache(string $key, callable $callable) {
		if (array_key_exists($key, $this->cache)) {
			return $this->cache[$key];
		}
		return $this->cache[$key] = $callable();
	}

	/**
	 * Remove a selected item from the internal cache
	 * 
	 * @param string|null $toClear the item to remove, or null for all
	 */
	public function clearCache(?string $toClear=null) : void {
		if (is_null($toClear)) {
			$this->cache = [];
		} else {
			unset($this->cache[$toClear]);
		}
	}

	/**
	 * Updates a column in the database
	 */
	protected function updateColumnInDatabase(string $column, $value) : void {
		$stmt = new UpdateQuery();

		$stmt->setTable($this->getTable());

		$stmt->addColumn(new Column($column, $this->getTable()));
		$stmt->addValue($value);

		$whereClause = new WhereClause();
		$whereClause->addToClause([new Column("ID", $this->getTable()), "=", $this->getId()]);
		$stmt->addAdditionalCapability($whereClause);

		$stmt->execute();

		$this->cache[$column] = $value;
	}

	/**
	 * Requirements from Serializable interface, gets a string representation of the User
	 * 
	 * Currently, this is the ID.  If this is changed, some form of versioning/verification will be needed
	 * @return string
	 */
	public function serialize() : string {
		return serialize($this->id);
	}

	/**
	 * Unserialize the User object, called upon session loading
	 * 
	 * @param string $data Serialized data
	 */
	public function unserialize($data) : void {
		$id = unserialize($data);

		if (!is_numeric($id) || (int)$id != $id) {
			throw new InvalidArgumentException("Invalid serialized data");
		}

		$this->id = (int)$id;

		$this->unserializeVerification();
	}

	/**
	 * @throws InvalidArgumentException if there is an error
	 */
	protected function unserializeVerification() {}

	/**
	 * Create a new object, CHECKS SHOULD BE MADE BY CLIENT
	 *
	 * Abstract so specific implementation of unpacking of $params can apply
	 * @param array $values values to build off of
	 */
	abstract public static function create(array $values);

	/**
	 * Get the values to overwrite deleted items
	 *
	 * @return array
	 */
	abstract public static function getDeletedValues() : array;

	/**
	 * Deletes the item (deletes images, deletes cache, fills row with deleted values)
	 */
	public function delete() : void {
		$this->additionalDeletion();

		$this->clearCache();

		$stmt = new UpdateQuery();

		$stmt->setTable(static::getTable());

		foreach (static::getDeletedValues() as $key => $value) {
			$stmt->addColumn(new Column($key, static::getTable()));
			$stmt->addValue($value);
		}

		$stmt->execute();

		if (method_exists($this, "getImage")) {
			$this->getImage()->delete();
		}
		if (method_exists($this, "getImageSet")) {
			foreach ($this->getImageSet() as $image) {
				$image->delete();
			}
		}
	}

	/**
	 * Can be overriden to allow for additional action to occur upon deletion
	 */
	protected function additionalDeletion() : void {}

	/**
	 * Return an array of format
	 * 	"Name" => ["COLUMN_NAME", function($value) {return $out;}, function($newValue) {return $out;}]
	 * 	e.g.
	 * 	"Color" => ["COLOR", "bin2hex", "hex2bin"]
	 *
	 * null callable for no function
	 *
	 * @return array
	 */
	protected abstract static function getModifiableProperties() : array;

	/**
	 * dynamic getters and setters uwu
	 */
	public function __call(string $name, array $arguments) {
		$type = "";
		if (strpos($name, "get") === 0) {
			$type = "get";
			$name = substr($name, 3);
			if (count($arguments) !== 0) {
				throw new BadMethodCallException("Invalid number of parameters passed to ".__CLASS__."::".$name." - recieved ".count($arguments)." but expected 0.");
			}
		} elseif (strpos($name, "is") === 0) {
			$type = "is";
			$name = substr($name, 2);
			if (count($arguments) !== 0) {
				throw new BadMethodCallException("Invalid number of parameters passed to ".__CLASS__."::".$name." - recieved ".count($arguments)." but expected 0.");
			}
		} elseif (strpos($name, "set") === 0) {
			$type = "set";
			$name = substr($name, 3);
			if (count($arguments) !== 1) {
				throw new BadMethodCallException("Invalid number of parameters passed to ".__CLASS__."::".$name." - recieved ".count($arguments)." but expected 1.");
			}
		} else {
			throw new BadMethodCallException($name." is not a method of ".__CLASS__);
		}

		if (!array_key_exists($name, static::getModifiableProperties())) {
			throw new BadMethodCallException($name." is not a method of ".__CLASS__);
		}

		$methodDefinition = static::getModifiableProperties()[$name];

		if ($type == "get" || $type == "is") {
			if (is_null($methodDefinition[1])) {
				if ($type == "is") {
					return (bool)$this->getColumnFromDatabaseOrCache($methodDefinition[0]);
				}
				return $this->getColumnFromDatabaseOrCache($methodDefinition[0]);
			} else {
				if ($type == "is") {
					if ($methodDefinition[1] instanceof Closure) {
						$methodDefinition[1]->bindTo($this, $this);
					}
					return (bool)call_user_func($methodDefinition[1], $this->getColumnFromDatabaseOrCache($methodDefinition[0]));
				}
				if ($methodDefinition[1] instanceof Closure) {
					$methodDefinition[1]->bindTo($this, $this);
				}
				return call_user_func($methodDefinition[1], $this->getColumnFromDatabaseOrCache($methodDefinition[0]));
			}
		} else {
			if (is_null($methodDefinition[2])) {
				if ($this->getColumnFromDatabaseOrCache($methodDefinition[0]) == $arguments[0]) {
					return;
				}
				$this->updateColumnInDatabase($methodDefinition[0], $arguments[0]);
			} else {
				if ($this->getColumnFromDatabaseOrCache($methodDefinition[0]) == call_user_func($methodDefinition[2], $arguments[0])) {
					return;
				}
				$this->updateColumnInDatabase($methodDefinition[0], call_user_func($methodDefinition[2], $arguments[0]));
			}
		}
	}
}
