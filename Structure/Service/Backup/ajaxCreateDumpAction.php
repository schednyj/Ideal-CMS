<?php
/**
 * Создаём дамп базы данных
 */
use Ideal\Core\Config;


if (isset($_POST['createMysqlDump'])) {
    // Подключаем библиотеку
    require_once 'Library/MySQLDump/mysqldump.php';

    $config = Config::getInstance();

    // Папка сохранения дампов
    $backupPart = stream_resolve_include_path($_POST['backupPart']);

    // Задаём параметры для создания бэкапа
    $dumpSettings = array(
        'compress' => 'GZIP',
        'no-data' => false,
        'add-drop-table' => false,
        'single-transaction' => false,
        'lock-tables' => false,
        'add-locks' => true,
        'extended-insert' => true
    );
    $dump = new Mysqldump($config->db['name'], $config->db['login'], $config->db['password'], $config->db['host'], 'mysql', $dumpSettings);

    $time = time();

    // Имя файла дампа
    $dumpName = 'dump_' . date('Y.m.d_H.i.s', $time) . '.sql';

    // Запускаем процесс выгрузки
    $tes = $dump->start($backupPart . DIRECTORY_SEPARATOR . $dumpName);

    $dumpName = $backupPart . '/' . $dumpName . '.gz';

    // Формируем строку с новым файлом
    echo '<tr id="' . $dumpName . '"><td><a href="" onClick="return downloadDump(\'' . $dumpName . '\')"> ' .
            date('d.m.Y - H:i:s', $time)
        . '</a></td>';
    echo '<td><button class="btn btn-danger btn-mini" title="Удалить" onclick="delDump(\'' . $dumpName . '\'); false;"> <i class="icon-remove icon-white"></i> </button></td>';
    echo '</tr>';

}
exit(false);