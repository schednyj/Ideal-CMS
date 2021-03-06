Ideal CMS v. 1.2.1
=========

Система управления контентом с открытым исходным кодом, написанная на PHP.

Используемые технологии и продукты:

* PHP 5.3+,
* MySQL 4+, 
* MVC, 
* PSR-0, PSR-1, PSR-2
* Twig, 
* jQuery,
* Twitter Bootstrap,
* CKEditor,
* CKFinder, 
* FirePHP.

Все подробности на сайте [idealcms.ru](http://idealcms.ru/)

Переход на версию 1.0
---

1. Во всех структурах поле structure_path изменено на prev_structure и содержит
ID родительской структуры и ID родительского элемента в этой структуре.

2. Изменён принцип роутинга. Теперь для вложенных структур метод detectPageByUrl
вызывается не из роутера, а из родительской структуры. Что даёт возможность
правильно обрабатывать вложенный структуры с элементами is_skip.

3. Изменён корневой .htacces, теперь адрес страницы не передаётся в GET-переменной,
а берётся в роутере из `$_SERVER['REQUEST_URI']`.

4. Переменная модели object переименована в pageData и сделана protected, а также
переименованы соответствующие методы.

5. Определение 404-ошибки перенесено из роутера в методы detectPageBy* модели.
В этих методах должны инициализироваться свойства класса path и is404, а сами
методы возвращают либо свой объект (`$this`), либо объект вложенной модели. Для
404 ошибки добавлен специальный шаблон 404.twig и экшен error404Action в контроллерах.