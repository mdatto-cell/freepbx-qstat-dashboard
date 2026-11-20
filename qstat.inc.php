<?php


// sysyem vars
$MainName = '#17 Узбечка Лермонтова';
$system_id = 'pbx17';
$internal_prefix = '17';
$callback_window_hours = 24;


// Client Name Lookup
$ClientNameLookup_iiko = true; 

// iiko: данные авторизации и параметры HTTP
$iiko_config = array(
    'api_login' => 'LOGIN',
    'organization_id' => 'UUID',
    'base_url' => 'https://api-ru.iiko.services',
    'connect_timeout' => 2,
    'request_timeout' => 5,
);

// time-zone
date_default_timezone_set('Europe/Moscow');


// db
$dsn = 'mysql:host=127.0.0.1;dbname=asteriskcdrdb;charset=utf8';
$dbUser = 'MYSQL_LOGIN';
$dbPass = 'MYSQL_PASS';

// did arr
$did_array = array(
    "78412505344"=>"Узбечка №17",
    "78412505311"=>"Узбечка №17 Доставка",
    "78412232630"=>"Сайт",
    "78412232631"=>"Доставка",
    "78412232632"=>"Yandex",
    "78412232633"=>"2gis",
    "78412232634"=>"VK",
    "78412232635"=>"Insta",
    "78412232636"=>"TG"
);

// queue array
$queue_array = array(
    "1777"=>"Хостес",
    "1778"=>"Доставка"
);

// Отображение таблицы «Итого» во входящих
$showIncomingSummary = false;

// Ключ — внутренний номер; значение — подпись кнопки.
$call_agents = array(
    '1701' => 'Хостес 1',
    '1702' => 'Хостес 2',
    '1704' => 'Хостес 3',
);
$call_ami_host = '127.0.0.1';
$call_ami_port = 5038;
$call_ami_user = 'AMI_USER';
$call_ami_secret = 'AMI_USER_PASS';
$call_local_prefix = '88452';
?>
