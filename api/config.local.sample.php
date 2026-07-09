<?php
/* ОБРАЗЕЦ. Скопируйте в config.local.php НА СЕРВЕРЕ и впишите реальные доступы.
   config.local.php добавлен в .gitignore — он НЕ попадёт в репозиторий и деплой.
   Как получить доступы к БД: панель Timeweb → «Базы данных MySQL» → создать базу и
   пользователя. Хост обычно localhost. */
return [
  // --- Слой 2: настоящие аккаунты (MySQL) ---
  'DB_ENABLED' => true,
  'DB_DSN'     => 'mysql:host=localhost;dbname=ВАША_БАЗА;charset=utf8mb4',
  'DB_USER'    => 'ВАШ_ПОЛЬЗОВАТЕЛЬ',
  'DB_PASS'    => 'ВАШ_ПАРОЛЬ',

  // --- Слой 3: проброс в CRM (необязательно) ---
  'CRM_ENABLED'=> false,
  'CRM_URL'    => 'https://ВАШ-CRM/api/register',
  'CRM_TOKEN'  => 'ТОКЕН_CRM',

  // домен фронтенда (для CORS)
  'ALLOW_ORIGIN' => 'https://zavod-red.ru',
];
