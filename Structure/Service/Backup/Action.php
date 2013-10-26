<button type="submit" name="createMysqlDump" onclick="createDump();" class="btn btn-primary">Создать backup базы</button>
<p id='textDumpStatus'></p>

<?php
use Ideal\Core\Config;

// Папка для хранения бэкапов
define('BACKUP_PART', '/tmp/backup/');
define('BACKUP_DIR', $_SERVER['DOCUMENT_ROOT'] . BACKUP_PART);

// Получение списка файлов
$dumpFiles = array();
if (is_dir(BACKUP_DIR)) {
    if ($dh = opendir(BACKUP_DIR)) {
        echo '<table id="dumpTable" class="table table-hover">';
        while (($file = readdir($dh)) !== false) {
            if (strripos($file, 'dump_') === false) continue;
            //$dumpFiles[] = $file;
            //$fn = str_replace('dump_', '',$file);
            $year = substr($file,5,4);
            $month = substr($file,10,2);
            $day = substr($file,13,2);
            $hour = substr($file,16,2);
            $minute = substr($file,19,2);
            $second = substr($file,22,2);

            echo '<tr id="' . $file . '"><td><a href="' . BACKUP_PART . $file . '"> ' . "$day/$month/$year - $hour:$minute:$second" . '</a></td>';
            echo '<td><button class="btn btn-danger btn-mini" title="Удалить" onclick="delDump(\'' . $file . '\'); false;"> <i class="icon-remove icon-white"></i> </button></td>';
            echo '</tr>';
        }
        closedir($dh);
        echo '</table>';
    }
}
?>

<script>
dir = '<?php echo BACKUP_DIR ?>';
// Удаление файла
function delDump(idFile) {
    var nameFile = dir + idFile;
    if (confirm('Удалить файл дампа базы?')) {
        $.ajax({
            url: 'index.php?par=3-Ideal_Backup&action=ajaxDelete',
            type: 'POST',
            data: {
                name: nameFile
            },
            success: function(data){
                //Выводим сообщение
                var message = data;
                if (message == true) {
                    var el = document.getElementById(idFile);
                    el.parentNode.removeChild(el);
                    $('#textDumpStatus').removeClass().addClass('text-success').html('Файл успешно удалён');
                } else {
                    $('#textDumpStatus').removeClass().addClass('text-error').html('Ошибка при удалении файла');
                }
            },
            error: function() {
                $('#textDumpStatus').removeClass().addClass('text-error').html('Не удалось удалить файл');
            }
        })
    } else {
        // Do nothing!
    }
}

// Создание дампа базы данных
function createDump() {
    $('#textDumpStatus').removeClass().addClass('text-info').html('Идёт создание дампа базы');
    $.ajax({
        url: 'index.php?par=3-Ideal_Backup&action=ajaxCreateDump',
        type: 'POST',
        data: {
            createMysqlDump: true,
            backupPart: '<?php echo BACKUP_PART?>'
        },
        success: function(data){
            //Выводим сообщение
            var message = data;
            if (message.length > 1) {
                $('#textDumpStatus').removeClass().addClass('text-success').html('Дамп базы создн');
                $('#dumpTable').append(data);
            } else {
                $('#textDumpStatus').removeClass().addClass('text-error').html('Ошибка при создании дампа базы');
            }
        },
        error: function() {
            $('#textDumpStatus').removeClass().addClass('text-error').html('Не удалось создать дамп базы');
        }
    })
}
</script>