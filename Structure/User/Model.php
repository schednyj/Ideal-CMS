<?php
namespace Ideal\Structure\User;

use Ideal\Core\Config;
use Ideal\Core\Db;

/**
 * Класс для работы с пользователем
 *
 */

session_start();

class Model
{
    public $data = array();         // массив с данными пользователя
    public $errorMessage = '';      // последнее сообщение об ошибке
    protected $_seance = '';        // наименование сессии и cookies
    protected $_session = array();  // считанная сессия этого сеанса
    protected $_table = 'ideal_structure_user';
    protected $loginRow = 'email';
    protected $loginRowName = 'e-mail';

    static $instance;


    public static function getInstance()
    {
        if (empty(self::$instance)) {
            self::$instance = new Model();
        }
        return self::$instance;
    }


    /**
     * Считывает данные о пользователе из сессии
     *
     */
    public function __construct()
    {
        $config = Config::getInstance();

        // Устанавливаем имя связанной таблицы
        $this->_table = $config->db['prefix'] . $this->_table;

        // TODO сделать считывание переменной сеанса из конфига

        // Инициализируем переменную сеанса
        if ($this->_seance == '') {
            $this->_seance = $config->domain;
        }

        // Загружаем данные о пользователе, если запущена сессия
        if (isset($_SESSION[$this->_seance])) {
            $this->_session = unserialize($_SESSION[$this->_seance]);
            $this->data = $this->_session['user_data'];
        } else {
            $this->data = array();
        }
    }


    public function checkLogin()
    {
        // Если пользователь не залогинен - возвращаем FALSE
        return isset($this->data['ID']);
    }


    /**
     * Проверка введённого пароля
     *
     * В случае удачной авторизации заполняется поле $this->data
     *
     * @param $login Имя пользователя
     * @param $pass  Пароль в md5()
     *
     * @return bool true, если удалось залогиниться, false, если не удалось
     */
    public function login($login, $pass)
    {
		$login = trim($login);
		$pass = trim($pass);
	
        // Если не указан логин или пароль - выходим с false
        if (!$login OR !$pass) {
            $this->errorMessage = "Необходимо указать и {$this->loginRowName}, и пароль.";
            return false;
        }

        // Получаем пользователя с указанным логином
        $db = Db::getInstance();
        $par = array(
            $this->loginRow => $login,
            'is_active' => 1
        );
        $user = $db->select($this->_table, $par);
        if (count($user) == 0) {
            $this->errorMessage = "Неверно указаны {$this->loginRowName} или пароль.";
            return false;
        }
        $user = $user[0];

        // Если юзера с таким логином не нашлось, или пароль не совпал - выходим с false
        if (($user[$this->loginRow] == '' )
		     OR (crypt($pass, $user['password']) != $user['password'])) {
            $this->logout();
            $this->errorMessage = "Неверно указаны {$this->loginRowName} или пароль.";
            return false;
        }

        // Если пользователь находится в процессе активации аккаунта
        if ($user['act_key'] != '' ) {
             $this->errorMessage = 'Этот аккаунт не активирован.';
            return false;
        }

        $user['last_visit'] = time();
        $this->data = $user;

        // Обновляем запись о последнем визите пользователя
        $db->update($this->_table, $user['ID'], $user);

        // Записываем данные о пользователе в сессию
        $this->_session['user_data'] = $this->data;
        return true;
    }


    /**
     * Выход юзера
     */
    public function logout ()
    {
        $this->data = $this->_session = array();
        unset($_SESSION[$this->_seance]);
    }


    public function __destruct()
    {
        if (isset($this->_session['user_data'])) {
            $_SESSION[$this->_seance] = serialize($this->_session);
        }
    }


    public function setLoginField($loginRow, $loginRowName)
    {
        $this->loginRow = $loginRow;
        $this->loginRowName = $loginRowName;
    }
}