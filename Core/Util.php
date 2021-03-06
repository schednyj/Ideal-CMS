<?php
namespace Ideal\Core;

use Ideal\Core\Config;

/**
 * Класс полезных функций
 *
 */
class Util
{
	static $errorArray;
	
    /**
     * Вывод сообщения об ошибке
     * @param string $txt Текст сообщения об ошибке
     */
    static function addError($txt)
    {
        $config = Config::getInstance();
        switch ($config->errorLog)
        {
            case 'file':
                // Вывод сообщения в текстовый файл
                $ff = DOCUMENT_ROOT. $config->cmsFolder . '/error.log';
                $fp = fopen($ff, 'a');
                $msg = Date('d.m.y H:i', time()) . '  ' . $_SERVER['REQUEST_URI'] . "\r\n";
                $msg .= $txt . "\r\n\r\n";
                fwrite($fp, $msg);
                fclose($fp);
                break;

            case 'display':
                // При возникновении ошибки, тут же её выводим на экран
                print $txt . '<br />';
                break;

            case 'comment':
                // При возникновении ошибки, выводим её закомментированно
                print '<!-- ' . $txt . " -->\r\n";
                break;

            case 'firebug':
                // Отображаем ошибку для просмотра через FireBug
                \FB::error($txt);
                break;

            case 'email':
            case 'var':
				self::$errorArray[] = $txt;
				break;

            default:
                break;
        }
    }

    public static function shutDown()
    {
        $config = Config::getInstance();
        if ($config->errorLog == 'email' && count(self::$errorArray) > 0) {
            $text = "Здравствуйте!\n\nНа странице http://{$config->domain}{$_SERVER['REQUEST_URI']} "
                  . "произошли следующие ошибки.\n\n"
                  . implode("\n", self::$errorArray);
            $mail = new \Mail\Sender();
            $mail->setSubj("Сообщение об ошибке на сайте " . $config->domain);
            $mail->setBody($text, '');
            $mail->sent($config->robotEmail, $config->mailForm);
        }
    }

    /**
     * Обработка адрес e-mail, переданного из браузера
     * @param string $str E-mail
     * @param int    $len Максимальная длина строки (по умолчанию 255)
     * @return string Безопасный и валидный адрес
     */
    static function parse_webmail($str, $len = 255)
    {
        // Cчитается, что передаётся одна строка, поэтому всё,
        // Что идёт за переводом строки - это хакеры
        $arr = explode("\n", $str);
        $str = $arr[0];
        $arr = explode("\r", $str);
        $str = $arr[0];
        // Обрезаем строку до нужного размера
        $str = substr($str, 0, $len);
        // Заменяем все спец. символы на их html-сущности
        // Преобразуются и двойные и одинарные кавычки
        $str = htmlspecialchars($str, ENT_QUOTES);
        return $str;
    }


    /**
     * Обрабатываем строку, переданную из браузера
     * @param string $str строка
     * @param int    $len Максимальная длина строки (по умолчанию 255)
     * @return string Безопасная строка
     */
    static function parse_webstr($str, $len = 255)
    {
        $str = Util::parse_webmail($str, $len);
        // Заменяем @ на собаку, в обычном тексте этот символ совершенно не нужен
        $str = str_replace('@', '[собака]', $str);
        return $str;
    }


    /**
     * Обрабатываем блок текста, переданный из браузера
     * @param string $str строка
     * @param int    $len Максимальная длина строки (по умолчанию 3072)
     * @return string Безопасный блок текста
     */
    static function parse_webarea( $str, $len = 3072 )
    {
        // Обрезаем строку до нужного размера
        $str = mb_substr($str, 0, $len);
        // Заменяем все спец. символы на их html-сущности
        $str = htmlspecialchars($str, ENT_QUOTES); // преобразуются и двойные и одинарные кавычки
        // Превращаем все переводы строки в <br>
        $str = nl2br($str);
        // Убираем переводы строк и возврат каретки
        $str = str_replace("\n", '', $str);
        $str = str_replace("\r", '', $str);
        // Заменяем @ на собаку, в блоке текста этот символ совершенно не нужен
        $str = str_replace('@', '[собака]', $str);

        return $str;
    }


    /**
     * Функция проверяет, нет ли в строке символов, отличных от букв и пробелов
     * @param string $str - исходная строка
     * @return boolean
     */
    static function is_no_spec_symbols($str)
    {
        $is_stop = false; // флаг наличия спецсимволов

        // Находим коды начальных и конечных букв алфавитов
        $Abig = ord('A');
        $Zbig = ord('Z');
        $Asml = ord('a');
        $Zsml = ord('z');
        $Arbig = ord('А');
        $Yabig = ord('Я');
        $Arsml = ord('а');
        $Yasml = ord('я');

        // Определяем длину строки, чтобы не высчитывать её в цикле
        $strlen = strlen($str);

        for ($i=0; $i<$strlen; $i++ ) {
            if ($str[$i] == '-') {
                continue;
            }
            if ($str[$i] == ' ') {
                continue;
            }
            if ($str[$i] == 'ё' or $str[$i] == 'Ё') {
                continue;
            }
            $j = ord($str[$i]);
            if ($j < $Abig) {
                $is_stop = true;
            }
            if ($j > $Zbig and $j < $Asml) {
                $is_stop = true;
            }
            if ($j > $Zsml and $j < $Arbig) {
                $is_stop = true;
            }
            if ($j > $Yabig and $j < $Arsml) {
                $is_stop = true;
            }
            if ($j > $Yasml) {
                $is_stop = true;
            }
            if ($is_stop) {
                break;
            }
        }

        return !$is_stop;
    }


    /**
     * Проверяет правильно ли написан адрес электронной почты
     *
     * @param string $mail - адрес электронной почты
     * @return bool - истина, если ящик написан правильно
     */
    static function is_email($mail)
    {
        // Проверяем правильно ли в мыле поставлены знаки @ и .
        $posAT = strpos($mail, '@');
        $posDOT = strrpos($mail, '.');
        if (($posAT < 1) or ($posDOT < 3) or ($posAT > $posDOT)) {
            return false;
        }

        //Проверяем, нет ли в мыле русских букв
        for ($i=0; $i<strlen($mail); $i++) {
            if (ord($mail[$i]) > 127) {
                return false;
            }
        }

        return true;
    }


    /**
     * Конвертация текст из кодировки сайта в кодировку базы (обычно UTF8->cp1251)
     * @param string $text - строка в кодировке сайта (обычно UTF8)
     * @return string строка в кодировке базы (обычно cp1251)
     */
    static function convertToDb($text)
    {
        $config = Config::getInstance();
        $siteCharset = 'UTF-8';

        if (function_exists('mb_convert_encoding')) {
            $text= mb_convert_encoding($text, $config->db['charset'], $siteCharset);
        } else {
            if (function_exists('iconv')) {
                $text = iconv($siteCharset, $config->db['charset'], $text);
            }
        }
        return $text;
    }


    /**
     * Конвертация текст из кодировки базы в кодировку сайа (обычно cp1251->UTF8)
     * @param string $text - строка в кодировке сайта (обычно cp1251)
     * @return string строка в кодировке базы (обычно UTF8)
     */
    static function convertFromDb($text)
    {
        $config = Config::getInstance();
        $siteCharset = 'UTF-8';

        if (function_exists('mb_convert_encoding')) {
            $text= mb_convert_encoding($text, $siteCharset, $config->db['charset']);
        } else {
            if (function_exists('iconv')) {
                $text = iconv($config->db['charset'], $siteCharset, $text);
            }
        }
        return $text;
    }


    static function detect_utf($Str)
    {
        for ($i=0; $i<strlen($Str); $i++) {
            if (ord($Str[$i]) < 0x80) {
                $n=0; # 0bbbbbbb
            } else {
                if ((ord($Str[$i]) & 0xE0) == 0xC0) {
                    $n=1; # 110bbbbb
                } else {
                    if ((ord($Str[$i]) & 0xF0) == 0xE0) {
                        $n=2; # 1110bbbb
                    } else {
                        if ((ord($Str[$i]) & 0xF0) == 0xF0) {
                            $n=3; # 1111bbbb
                        } else {
                            return false; # Does not match any model
                        }
                    }
                }
            }
            for ($j=0; $j<$n; $j++) { # n octets that match 10bbbbbb follow ?
                if ((++$i == strlen($Str)) || ((ord($Str[$i]) & 0xC0) != 0x80)) {
                    return false;
                }
            }
        }
        return true;
    }


    /**
     * Обрезает строку $str до длинны $len и убирает все символы в конце
     * строки до последнего пробела
     *
     * @param string $str исходная строка
     * @param int    $len максимальное количество символов в строке
     * @return string
     */
    static function smartTrim($str, $len)
    {
        $firstLen = mb_strlen($str);
        $str = mb_substr($str, 0, $len);
        if ($firstLen !== mb_strlen($str)) $str = mb_substr($str, 0, mb_strrpos($str, ' '));
        return $str;
    }


    /**
     * Возвращает отформатированную дату, с названием месяца на русском
     * @param $date - дата в формате timestamp
     * @return string строка с отформатированной датой
     */
    static function dateReach($date)
    {
        $months = array('', 'января', 'февраля', 'марта', 'апреля', 'мая', 
                        'июня', 'июля', 'августа', 'сентября', 'октября',
                        'ноября', 'декабря');
        $date = date('j', $date) . ' ' . $months[date('n', $date)] . ' ' . 
                date('Y', $date) . ' года';
        return $date;
    }


    /**
     * Возвращает отформатированную дату из даты в текстовом формате, с названием месяца на русском
     * @param $date - дата в текстовом формате
     * @return string строка с отформатированной датой
     */
    static function dateStrReach($date)
    {
        $months = array('', 'января', 'февраля', 'марта', 'апреля', 'мая', 
                        'июня', 'июля', 'августа', 'сентября', 'октября',
                        'ноября', 'декабря');
        $date = explode(' ', $date);
        $date = explode('-', $date[0]);
        $day = (int)$date[1];
        $date = $date[2] . ' ' . $months[$day] . ' ' . $date[0] . ' года';
        return $date;
    }


    /**
     * Генерирует одну или несколько случайных латинских букв (больших или маленьких)
     *
     * @param int $len Количество символов
     *
     * @return string Латинская буква - большая или маленькая
     */
    function randomChar($len = 1)
    {
        $str = '';
        for ($i = 0; $i < $len; $i++) {
            $chr1 = chr(rand(65, 90));
            $chr2 = chr(rand(97, 122));
            $is = rand(0, 1);
            if ($is == 0) {
                $str .= $chr1;
            } else {
                $str .= $chr2;
            }
        }
        return $str;
    }


    /**
     * Переход на страницу логина с сохранением страницы, на которую не пустило
     *
     * @param string $link Ссылка на страницу авторизации
     */
    function goUrl($link)
    {
        $_SESSION['prev_post'] = serialize($_POST);
        $_SESSION['prev_uri'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . $link);
        exit;
    }


    static function getClassName($module, $type)
    {
        list($module, $structure) = explode('_', $module);
        $name = '\\' . $module . '\\' . $type . '\\' . $structure;
        return $name;
    }
}