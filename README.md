# pdo-mongo-cache
Provide caching utilities as a PDO decorator via [APIx Cache](https://github.com/frqnck/apix-cache)
and [pdo-decorator](https://github.com/konapun/pdo-decorator).

## Installing MongoDB / Enabling support in PHP (CentOS 6)
### Installing ([Guide](http://www.expert-linux.com/2014/11/05/mongodb-on-centos-6-6-getting-started/))
  Create the following file as **/etc/yum.repos.d/MongoDB.repo**

    [mongodb]
    name=MongoDB Repository
    baseurl=http://downloads-distro.mongodb.org/repo/redhat/os/x86_64/
    gpgcheck=0
    enabled=1

  Install from yum

    yum install -y mongodb-org

  Start service

    chkconfig mongod on
    service mongod start

### Enabling PHP support
  Prereqs:

    yum -y install gcc php-pear php-devel
    pecl install mongo

  Place the following line under **Dynamic Extensions** in /etc/php.ini:

    extension=mongo.so

  Now restart the service:

    service httpd restart

## Usage
Since pdo-mongo-cache is just a decorator for PDO, use it as you would normally use PDO

```php
use PDO\Decorator\TimedQueryDecorator as TimeDecorator;
use PDO\Cache\MongoPDOCache as MongoDecorator;

$username = "..."; // your username for the PDO database to connect to
$password = "..."; // your password for the PDO database
$dsn = "..."; // DSN for the PDO connection
$pdo = new PDO($dsn, $username, $password);
$cachedPDO = new MongoDecorator($pdo, array(
  'db_name' => 'apix', // this is the name of the Mongo database to use for caching
  'collection_name' => 'cache', // name of mongo collection
  'object_serializer' => 'php' // php, igBinary, json
));

$query = "..."; // your query here
$cacheStmt = $cachedPDO->prepare($query);

/*
 * If this is the first time this unique combination of query and args was run,
 * the statement will be executed and cached. Else, the cached result will be
 * used as the state for this statement to be returned via fetch or fetchAll
 */
$cacheStmt->execute();
$results = $cacheStmt->fetchAll();
```
