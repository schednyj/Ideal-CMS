<?php
/**
 * Ideal CMS (http://idealcms.ru/)
 *
 * @link      http://github.com/ideals/idealcms репозиторий исходного кода
 * @copyright Copyright (c) 2012-2014 Ideal CMS (http://idealcms.ru)
 * @license   http://idealcms.ru/license.html LGPL v3
 */

namespace Ideal\Core;

/**
 * Класс NewDb — обёртка над mysqli, добавляющий следующие улучшения
 * + одноразовое подключение к БД в рамках одного запуска php-интерпретатора
 * + класс сам обращается к настройкам подключения в конфигурационном файле CMS
 * + вспомогательные методы для запросов SELECT, INSERT, UPDATE, экранирующие входные параметры
 * + метод create, для создания таблиц на основе настроек CMS
 * В рамках CMS класс используется следующим образом
 *     $db = Db::getInstance();
 *     $par = array('time' = time(), 'active' = 1);
 *     $fields = array('table', 'example_table');
 *     $rows = $db->select('SELECT * FROM &table WHERE time < :time AND is_active = :active',
 *                     $par, $fields);
 * В переменной $rows окажется ассоциативный массив записей из таблицы `example_table`,
 * у которых поле `time` меньше чем текущее время, а поле `is_active` равно 1
 */

class Db extends \mysqli
{
    /** @var array Массив для хранения подключений к разным БД */
    protected static $instance;
    /** @var array Массив для хранения пар ключ-значение метода set() */
    protected $updateValues = array();
    /** @var array Массив для хранения пар ключ-значение метода where() */
    protected $whereParams = array();
    /** @var string Название используемой БД для корректного кэширования запросов */
    protected $dbName;
    /** @var Memcache Экземпляр подключения к memcache */
    protected $cache;
    /** @var array Массив для хранения явно указанных таблиц при вызове cacheMe() */
    protected $involvedTables;
    /** @var string Строка с where-частью запроса */
    protected $whereQuery = '';
    /** @var string Название таблицы для запроса UPDATE */
    protected $updateTableName = '';
    /** @var string Название таблицы для запроса DELETE */
    protected $deleteTableName = '';
    /** @var bool Флаг того, что следующий запрос надо попытаться взять из кэша */
    protected $cacheEnabled = false;

    /**
     * Получение singleton-объекта подключённого к БД
     *
     * Если переменная $params не задана, то данные для подключения берутся из конфигурационного файла CMS
     * В массива $params должны быть следующие элементы:
     * host, login, password, name
     *
     * @param array $params Параметры подключения к БД
     * @return bool|NewDb объект, подключённый к БД, false — в случае невозможности подключиться к БД
     */
    public static function getInstance($params = null)
    {
        $key = md5(serialize($params));

        if (!empty(self::$instance[$key])) {
            // Если singleton этого подключения инициализирован, возвращаем его
            return self::$instance[$key];
        }

        if (is_null($params)) {
            // Если параметры подключения явно не заданы, берём их из конфигурации
            $config = Config::getInstance();
            $params = $config->db;
        }

        $db = new Db($params['host'], $params['login'], $params['password'], $params['name']);

        if ($db->connect_errno) {
            Util::addError("Не удалось подключиться к MySQL: " . $db->connect_error);
            return false;
        }

        // Работаем только с UTF-8
        $db->query('set character set utf8');
        $db->query('set names utf8');

        $db->dbName = $params['name'];
        $db->cache = Memcache::getInstance();
        self::$instance[$key] = $db;

        return $db;
    }

    /**
     * Вставка новой строки в таблицу
     *
     * Пример использования:
     *     $params = array(
     *       'firstField' => 'firstValue',
     *       'secondField' => 'secondValue',
     *     )
     * $id = $db->insert('table', $params);
     * ВНИМАНИЕ: в результате выполнения этого метода сбрасывается кэш БД
     *
     * @param string $table  Таблица, в которую необходимо вставить строку
     * @param array  $params Значения полей для вставки строки
     * @return int ID вставленной строки
     */
    public function insert($table, $params)
    {
        $this->clearCache($table);
        $columns = $values = array();

        foreach ($params as $column => $value) {
            $columns[] = "`" . parent::real_escape_string($column) . "`";
            $values[] = "'" . parent::real_escape_string($value) . "'";
        }

        $columns = implode(', ', $columns);
        $values = implode(', ', $values);
        $table = parent::real_escape_string($table);
        $sql = 'INSERT INTO `' . $table . '` (' . $columns . ') VALUES (' . $values . ');';
        $this->query($sql);

        return $this->insert_id;
    }

    /**
     * Обновление одной или нескольких строк
     *
     * Пример использования:
     *     $db->update($table)->set($values)->where($sql, $params)->exec();
     * ВНИМАНИЕ: в результате выполнения этого метода сбрасывается кэш БД
     *
     * @param string $table Таблица, в которой будут обновляться строки
     * @return $this
     */
    public function update($table)
    {
        // Очищаем set и where, если они были заданы ранее
        // Записываем название таблицы для UPDATE

        $this->clearQueryAttributes();
        $this->updateTableName = $table;

        return $this;
    }

    /**
     * Удаление одной или нескольких строк
     *
     * Пример использования:
     *     $db->delete($table)->where($sql, $params)->exec();
     * ВНИМАНИЕ: в результате выполнения этого метода сбрасывается кэш БД
     *
     * @param string $table Таблица, в которой будут удаляться строки
     * @return $this
     */
    public function delete($table)
    {
        // Очищаем where, если он был задан ранее
        // Записываем название таблицы для DELETE

        $this->clearQueryAttributes();
        $this->deleteTableName = $table;

        return $this;
    }

    /**
     * Выборка строк из БД по заданному запросу $sql
     *
     * Пример использования:
     *     $par = array('time' => time(), active => true);
     *     $fields = array('table' => 'full_table_name');
     *     $rows = $db->select('SELECT * FROM &table WHERE time < :time AND is_active = :active', $par, $fields);
     *
     * @param string $sql    SELECT-запрос
     * @param array  $params Параметров, которые будут экранированы и закавычены как параметры
     * @param array  $fields Названий полей и таблиц, которые будут экранированы и закавычены как названия полей
     * @return array Ассоциативный массив сделанной выборки из БД
     */
    public function select($sql, $params = null, $fields = null)
    {
        $sql = $this->prepareSql($sql, $params, $fields);

        if (!$this->cacheEnabled) {
            // Если кэширование не включено, то выполняем запрос и возвращаем результат в виде ассоциативного массива
            return $this->query($sql)->fetch_all(MYSQLI_ASSOC);
        }

        $this->cacheEnabled = false; // т.к. кэширование включается только для одного запроса

        $cacheKey = $this->prepareCacheKey($sql);

        if ($cachedResult = $this->cache->getWithTags($cacheKey)) {
            return $cachedResult;
        }

        $queryResult = $this->query($sql)->fetch_all(MYSQLI_ASSOC);
        $cacheTags = $this->prepareCacheTags($sql);
        $this->cache->setWithTags($cacheKey, $queryResult, false, $cacheTags);

        return $queryResult;
    }

    /**
     * Установка флага попытки получения из кэша результатов следующего select-запроса
     *
     * @param array $involvedTables Массив с именами таблиц, участвующих в запросе.
     *                              Используется в случаях, когда SQL-запрос содержит JOIN или
     *                              вложенные подзапросы
     * @return $this
     */
    public function cacheMe($involvedTables = null)
    {
        if ($involvedTables) {
            $this->involvedTables = $involvedTables;
        }

        $this->cacheEnabled = true;
        return $this;
    }

    /**
     * Очистка кэша запросов, связанных с таблицей $table
     *
     * @param string $table Название таблицы, для запросов из которой нужно очистить кэш
     */
    public function clearCache($table)
    {
        $this->cache->deleteByTag($table);
    }

    /**
     * Создание таблицы $table на основе данных полей $fields
     *
     * @param string $table  Название создаваемой таблицы
     * @param array  $fields Названия создаваемых полей и описания их типа
     * @return bool|\mysqli_result
     */
    public function create($table, $fields)
    {
        $sqlFields = array();

        foreach ($fields as $key => $value) {
            $sqlFields[] = "`{$key}` {$value['sql']} COMMENT '{$value['label']}'";
        }

        $sql = "CREATE TABLE `{$table}` (" . implode(',', $sqlFields) . ') DEFAULT CHARSET=utf8';

        return $this->query($sql);
    }

    /**
     * В формируемый update-запрос добавляет значения полей для вставки
     *
     * @param array $values Названия и значения полей для вставки строки в таблицу
     * @return $this
     */
    public function set(array $values)
    {
        $this->updateValues = $values;
        return $this;
    }

    /**
     * В формируемый update/delete-запрос добавляет where-условие
     *
     * @param string $sql    Строка where-условия
     * @param array  $params Параметры, используемые в строке where-условия
     * @return $this
     */
    public function where($sql, $params)
    {
        $this->whereQuery = $sql;
        $this->whereParams = $params;

        return $this;
    }

    /**
     * Выполняет сформированный update/delete-запрос
     *
     * @param bool $exec Флаг выполнять/возвращать сформированный sql-запрос
     * @return bool|string Либо флаг успешности выполнения запроса, либо сам sql-запрос
     */
    public function exec($exec = true)
    {
        if (!$this->updateTableName && !$this->deleteTableName) {
            Util::addError('Попытка вызова exec() без update() или delete().');
            return false;
        }

        $tag = $this->updateTableName ? $this->updateTableName : $this->deleteTableName;
        $sql = $this->updateTableName ? $this->getUpdateQuery() : $this->getDeleteQuery();

        if ($exec) {
            $this->clearCache($tag);
            $this->query($sql);
            $this->clearQueryAttributes();
        } else {
            return $sql;
        }
        return true;
    }

    /**
     * Выполняет запрос к базе данных
     *
     * @link http://php.net/manual/ru/mysqli.query.php
     * @param string $query
     * @param int    $resultMode
     * @return bool|\mysqli_result
     */
    public function query($query, $resultMode = MYSQLI_STORE_RESULT)
    {
        $result = parent::query($query, $resultMode);

        if ($this->error) {
            Util::addError($this->error . PHP_EOL . 'Query: ' . $query);
        }

        return $result;
    }

    /**
     * Очистка параметров текущего update/delete запроса
     */
    protected function clearQueryAttributes()
    {
        $this->updateTableName = $this->deleteTableName = $this->whereParams = '';
        $this->updateValues = $this->whereParams = array();
        $this->involvedTables = null;
    }

    /**
     * Подготовка запроса к выполнению
     *
     * Все значения из $params экранируются и подставляются в $sql на место
     * плейсхолдеров :fieldName, имена таблиц подставляются на место
     * плейсхолдера &table
     *
     * @param string $sql    Необработанный SQL-запрос
     * @param array  $params Массив пар поле-значение, участвующих в запросе $sql
     * @param array  $fields Имена таблиц участвующих в запросе $sql
     * @return string Подготовленный SQL-запрос
     */
    protected function prepareSql($sql, $params = null, $fields = null)
    {
        if (is_array($params)) {
            foreach ($params as $key => $value) {
                $value = parent::real_escape_string($value);
                $sql = str_replace(":{$key}", "'$value'", $sql);
            }
        }

        if (is_array($fields)) {
            foreach ($fields as $key => $value) {
                $field = parent::real_escape_string($value);
                $sql = str_replace("&{$key}", "`$field`", $sql);
            }
        }

        return $sql;
    }

    /**
     * Возвращает SQL-запрос для операции update() на основе значений, заданных с использованием set() и where()
     *
     * @return string UPDATE запрос
     */
    protected function getUpdateQuery()
    {
        $values = array();

        foreach ($this->updateValues as $column => $value) {
            $column = "`" . parent::real_escape_string($column) . "`";
            $value = "'" . parent::real_escape_string($value) . "'";
            $values[] = "{$column} = {$value}";
        }

        $values = implode(', ', $values);
        $this->updateTableName = "`" . parent::real_escape_string($this->updateTableName) . "`";
        $where = '';

        if ($this->whereParams && $this->whereQuery) {
            $where = 'WHERE ' . $this->prepareSql($this->whereQuery, $this->whereParams);
        }

        return 'UPDATE ' . $this->updateTableName . ' SET ' . $values . ' ' . $where . ';';
    }

    /**
     * Возвращает SQL-запрос для операции delete() на основе значений, заданных с использованием where()
     *
     * @return string DELETE запрос
     */
    protected function getDeleteQuery()
    {
        $this->deleteTableName = "`" . parent::real_escape_string($this->deleteTableName) . "`";
        $where = '';

        if ($this->whereParams && $this->whereQuery) {
            $where = 'WHERE ' . $this->prepareSql($this->whereQuery, $this->whereParams);
        }

        return 'DELETE FROM ' . $this->deleteTableName . ' ' . $where . ';';
    }

    /**
     * Возвращает ключ для кеширования запроса
     *
     * @param $query string SQL-запрос
     * @return string md5 от запроса, переведенного в нижний регистр
     */
    protected function prepareCacheKey($query)
    {
        return md5(strtolower($this->dbName . $query));
    }

    /**
     * Возвращает массив тегов, полученных на основе SQL-запроса
     *
     * Запрос разбирается в случае если теги (имена таблиц) явно не указаны при вызове cacheMe().
     *
     * @param $query string SQL-запрос
     * @return array
     */
    protected function prepareCacheTags($query)
    {
        if ($this->involvedTables) {
            return $this->involvedTables;
        }

        // Запрос переводится в нижний регистр, после чего из него вырезаются
        // все символы до последнего ключевого слова FROM
        // и все символы начиная со следующего возможного ключевого слова

        $query = strtolower($query);
        $query = preg_replace('/^(.|\n)*from\s+/i', '', $query);
        $pattern = '/\s+(join\s+|left\s+|right\s+|where\s+|group\s+by|having\s+|order\s+by|limit\s+)(.|\n)*$/i';
        $query = preg_replace($pattern, '', $query);

        // Полученное значение разбивается на массив и очищается от кавычек и псевдонимов

        if (strpos($query, ',') !== false) {
            $query = explode(',', $query);
        }

        if (!is_array($query)) {
            $query = array($query);
        }

        foreach ($query as $key => $value) {
            $value = str_replace(array('\'', '"', '`'), '', $value);
            $asPosition = strpos($value, ' as ');

            if ($asPosition !== false) {
                $value = substr($value, 0, $asPosition);
            }

            $value = trim($value);
            $query[$key] = $value;
        }

        return array_unique($query);
    }
}
