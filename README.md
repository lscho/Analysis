A MySQL log analysis tool

## Install 

`composer require lscho/analysis dev-master`

## Use

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Analysis\MysqlBinLog;
$MysqlBinLog = MysqlBinLog::getInstance([
        'host' => '127.0.0.1',
        'username' => 'root',
        'password' => '123456',
        'database' => 'test',
    ]);
$data = $MysqlBinLog->getData();
//print_r($data);
```


## License

- MIT