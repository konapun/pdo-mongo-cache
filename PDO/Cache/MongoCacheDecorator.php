<?php
namespace PDO\Cache;

use Apix\Cache;
use PDO\Exception\NotImplementedException as NotImplementedException;
use PDO\Decorator\PDODecorator as PDODecorator;
use PDO\Decorator\PDOStatementDecorator as PDOStatementDecorator;

/*
 * Automatically cache results as JSON in a MongoDB using Apix-Cache
 */
class MongoPDOCache extends PDODecorator  {
  private $cache;

  /*
   * Construct this decorator using options to pass to APIx
   */
  function __construct($concretePDO, $apixOptions) {
    parent::__construct($concretePDO);
    $apixCache = $this->instantiateCache($apixOptions);
    $this->cache = new MongoCache($apixCache);
  }

  /*
   * Run a query and return a decorated MongoStatementCache as the result
   */
  function query($statement) {
    $cache = $this->cache;

    if (($data = $cache->load($statement)) !== null) {
      return $data;
    }

    $mongoStatement = new MongoStatementCache(parent::query($statement), $cache);
    $cache->save($statement);
    return $statement;
  }

  /*
   * Return a decorated statement
   */
  function prepare($query, $driver_options=array()) {
    $statement = parent::prepare($query, $driver_options);
    if ($statement === false) return $statement;
    return new MongoStatementCache($statement, $query, $this->cache);
  }

  private function instantiateCache($options) {
    $mongo = new \MongoClient;
    return new \Apix\Cache\Mongo($mongo, $options);
  }
}

/*
 * The actual query cache, featuring automatic hash key creation
 */
class MongoCache {
  private $cache;

  function __construct($cache) {
    $this->cache = $cache;
  }

  function load($query) {
    return $this->cache->load($this->generateHashKey($query));
  }

  function save($query, $results) {
    $this->cache->save($results, $this->generateHashKey($query));
  }

  /*
   * FIXME: Account for spacing variants
   */
  private function generateHashKey($statement) {
    return md5($statement);
  }
}

class MongoStatementCache extends PDOStatementDecorator {
  private $cache;
  private $query;
  private $params; // used for building cache key
  private $fetchResults; // cached state since PDO can't be serialized
  private $cursor; // position in fetchedResults

  function __construct($concreteStatement, $query, $cache) {
    parent::__construct($concreteStatement);
    $this->cache = $cache;
    $this->query = $query;
    $this->params = array();
    $this->fetchResults = array();
    $this->cursor = 0;
  }

  function bindColumn($column, &$param, $type=null, $maxlen=null, $driverdata=null) {
    $this->params[$column] = $param;
    return parent::bindColumn($column, $param, $type, $maxlen, $driverdata);
  }

  function bindParam($parameter, &$variable, $data_type=\PDO::PARAM_STR, $length=null, $driver_options=null) {
    $this->params[$parameter] = $variable;
    return parent::bindParam($parameter, $variable, $data_type, $length, $driver_options);
  }

  function bindValue($parameter, $value, $data_type=\PDO::PARAM_STR) {
    $this->params[$parameter] = $value;
    return parent::bindValue($parameter, $value, $data_type);
  }

  /*
   * Execute a query and cache the results
   * - FIXME only cache for SELECT statements...
   */
  function execute($input_parameters=array()) {
    foreach ($input_parameters as $pkey => $pval) {
      $this->params[$pkey] = $pval;
    }

    $cache = $this->cache;
    $query = $this->insertQueryParams($this->query, $this->params);
    if (($data = $cache->load($query)) !== null) { // cached version exists
      $this->fetchResults = $data['state'];
      return $data['value'];
    }

    $value = $this->getStatement()->execute($input_parameters);
    $state = $this->getStatement()->fetchAll(); // FIXME - with decorators applied this fails
    $this->fetchResults = $state;
    $this->cursor = 0;
    if ($state === false) {
      return false;
    }

    $cache->save($query, array( // only cache if execute and fetch succeeded
      'state' => $state,
      'value' => $value
    ));
    return $value;
  }

  /*
   * Fetch one result
   */
  function fetch($fetch_style=\PDO::FETCH_BOTH, $cursor_orientation=\PDO::FETCH_ORI_NEXT, $cursor_offset=0) {
    $this->ensureArgumentsSupported($fetch_style, $cursor_orientation, $cursor_offset);

    return $this->fetchResults[$this->cursor++];
  }

  /*
   * Fetch all results from stored state
   */
  function fetchAll($fetch_style=\PDO::FETCH_BOTH, $fetch_argument=null, $ctor_args=array()) {
    $this->ensureArgumentsSupported($fetch_style, $fetch_argument); // FIXME

    return $this->fetchResults;
  }

  function fetchColumn($column_number=null) {
    return $this->concreteStatement->fetchColumn($column_number=0);
  }

  function rowCount() {
    $cachedCount = count($this->fetchResults);
    return $cachedCount > 0 ? $cachedCount : parent::rowCount();
  }

  /*
   * Replace placeholders with real arguments in a prepared statement for
   * caching
   */
  private function insertQueryParams($query, $params) {
    $inserted = $query;
    foreach ($params as $pkey => $pval) {
      if ($pkey[0] != ':') $pkey = ':' . $pkey; // add initial : to query param
      $inserted = str_replace($pkey, $pval, $inserted);
    }

    return $inserted;
  }

  private function ensureArgumentsSupported($fetch_style, $cursor_orientation=false, $cursor_offset=0) {
    if ($fetch_style != \PDO::FETCH_BOTH) {
      throw new NotImplementedException("MongoCacheDecorator does not (yet) support fetch styles other than PDO::FETCH_BOTH");
    }
    if ($cursor_orientation !== false) {
      if ($cursor_orientation != \PDO::FETCH_ORI_NEXT) {
        throw new NotImplementedException("MongoCacheDecorator does not (yet) support cursor orientations other than PDO::FETCH_ORI_NEXT");
      }
    }
    if ($cursor_offset != 0) {
      throw new NotImplementedException("MongoCacheDecorator does not (yet) support non-zero cursor offsets");
    }
  }
}
?>
