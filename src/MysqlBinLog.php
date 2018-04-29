<?php
namespace Analysis;

/**
 * mysql bin log 日志分析工具
 */
class MysqlBinLog
{
    private $config = [
        'host' => '127.0.0.1',
        'username' => 'root',
        'password' => '123456',
        'database' => 'test',
    ];

    private static $instance;
    private $db;
    private $tableName = "";
    private $reset = false;

    private function __construct($config = [])
    {
        if ($config) {
            $this->config = array_merge($this->config, $config);
        }
    }

    public static function getInstance($config = [])
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * 执行数据库查询
     * @param  string $sql sql语句
     * @return boolean|mysqli_result
     */
    private function query($sql = "")
    {
        if (!$this->db) {
            $db = mysqli_connect($this->config['host'], $this->config['username'], $this->config['password'], $this->config['database']);
            if (!$db) {
                die('Could not connect: ' . mysqli_error());
            } else {
                $this->db = $db;
            }
        }
        if (!$sql) {
            return false;
        }
        $result = $this->db->query($sql);
        return $result;
    }

    /**
     * 获取log列表
     * @return [type] [description]
     */
    private function getMasterLogFiles()
    {
        $sql = "show master logs;";
        $result = $this->query($sql);
        $files = [];
        if ($result) {
            while ($row = $result->fetch_row()) {
                $files[] = $row[0];
            }
        }
        return $files;
    }

    private function getColumn($tableName){
        if(!$tableName){
            return [];
        }
        $sql="select COLUMN_NAME from information_schema.COLUMNS where table_name = '".$tableName."' and table_schema = '".$this->config['database']."';";
        $result = $this->query($sql);
        $columns=[];
        if ($result) {
            while ($row = $result->fetch_row()) {
                $columns[] = $row[0];
            }
        }
        return $columns;
    }

    /**
     * 解析sql语句
     * @param  string $sql [description]
     * @return [type]      [description]
     */
    private function parseSql($sql = "")
    {
        preg_match('/(INSERT)|(DELETE)|(UPDATE)/', $sql, $types);
        if (!$types || !$types[0]) {
            return false;
        }
        switch ($types[0]) {
            case "INSERT":
                $type = "insert";
                preg_match('/INSERT\s+INTO\s+`(.*)`\s*\((.*?)\)\s*VALUES\s*\((.*)\)/', $sql, $insertMatches);
                if(!$insertMatches){
                    return false;
                }
                $tableName = $insertMatches[1];
                if(!empty($insertMatches[2])){
                    $keys = json_decode('[' . str_replace('`', '"', str_replace(['(',')'],"",$insertMatches[2])) . ']', true);
                }else{
                    $keys = $this->getColumn($tableName);
                }
                $values = json_decode('[' . str_replace("'", '"', $insertMatches[3]) . ']', true);
                $data = !empty($keys)&&!empty($values)&&count($keys)==count($values)?array_combine($keys, $values):[];
                break;
            case "UPDATE":
                $type = "update";
                preg_match('/UPDATE(\s+)`(.*)`(\s+)SET(\s+)(.*)(\s+)WHERE (.*)/', $sql, $updateMatches);
                if(!$updateMatches){
                    return false;
                }
                if(strpos($updateMatches[2], '.')!==false){
                    $temp=explode('.', $updateMatches[2]);
                    $tableName=str_replace(['`'],"",$temp[1]);
                }else{
                    $tableName = $updateMatches[2];
                }
                $data = json_decode('{' . str_replace(["'", "`", "="], ['"', '"', ":"], $updateMatches[5]) . '}', true);
                break;
            case "DELETE":
                $type = "delete";
                preg_match('/DELETE(\s+)FROM(\s+)`(.*)`(\s+)WHERE(\s+)(.*)/', $sql, $deleteMatches);
                if(!$deleteMatches){
                    return false;
                }
                $tableName = $deleteMatches[3];
                $data = [];
                break;
            default:
                return false;
                break;
        }

        return [
            'type' => $type,
            'tableName' => $tableName,
            'data' => $data,
            'sql' => $sql,
        ];
    }

    /**
     * 解析数据
     * @param  array  $data [description]
     * @return [type]       [description]
     */
    private function parse($logs)
    {
        $data = [];
        foreach ($logs as $key => $log) {
            if (strpos($log['info'], 'use') !== false) {
                $info = explode('; ', $log['info']);
                // 获取sql语句
                $sql = $info[1];
                // 获取数据库名字
                preg_match('/use(\s+)`(.*)`/', $info[0], $databaseMatches);
                $database = $databaseMatches[2];
                // 过滤数据库
                if (!$this->config['database'] || $this->config['database'] == $database) {
                    // 解析sql语句
                    $parseData = $this->parseSql($sql);

                    if ($parseData && ($this->tableName != "" || $this->tableName != $parseData['tableName'])) {
                        $data[] = $parseData;
                    }
                }
            }
        }
        return $data;
    }

    /**
     * 获取日志信息
     * @return [type] [description]
     */
    public function getLogs()
    {
        $files = $this->getMasterLogFiles();
        if (!$files) {
            return false;
        }
        $logs = [];
        foreach ($files as $file) {
            $sql = "show binlog events in '" . $file . "';";
            $result = $this->query($sql);
            if ($result) {
                while ($row = $result->fetch_row()) {
                    $eventType = $row[2];
                    $endLogPos = $row[4];
                    if ($eventType == 'Query' || $eventType == 'Intvar') {
                        $logs[$endLogPos] = [
                            'pos' => $row[1],
                            'endLogPos' => $endLogPos,
                            'info' => $row[5],
                        ];
                    }
                }
            }
            if ($this->reset === true) {
                $sql = "reset master;";
                $this->query($sql);
            }
        }
        if (!$logs) {
            return false;
        }

        $data = $this->parse($logs);

        return $data;
    }

    /**
     * 设置过滤的表名
     * @param  string $tableName [description]
     * @return [type]            [description]
     */
    public function table($tableName = "")
    {
        $this->tableName = $tableName;
        return $this;
    }

    /**
     * 设置配置
     * @param array
     * @return [<description>]
     */
    public function setConfig($config = [])
    {
        if ($config) {
            $this->config = array_merge($this->config, $config);
            $this->db = "";
        }
        return $this;
    }

    /**
     * 是否清除日志
     * @param  boolean
     * @return [type]
     */
    public function resetMaster($reset = false)
    {
        $this->reset = $reset;
        return $this;
    }

    /**
     * 防止clone
     * @return [type] [description]
     */
    private function __clone()
    {

    }
}
