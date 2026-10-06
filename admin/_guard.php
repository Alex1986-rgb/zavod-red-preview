<?php
declare(strict_types=1);

/**
 * Гард админ-страниц. Подключать в самом начале каждой страницы (кроме login.php).
 * Нет сессии → редирект на login.php.
 */
require_once __DIR__ . '/../api/helpers.php';
require_auth_html();

// Заголовки безопасности для админ-страниц (работа с ПДн клиентов).
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

/**
 * Однократная авто-миграция схемы под инженерную воронку + модули Письма/База знаний.
 * Срабатывает один раз (флаг crm_settings.schema_funnel=1), дальше — мгновенный пропуск.
 * Идемпотентно и не блокирует админку при ошибке. Позволяет обновляться ТОЛЬКО через git,
 * без ручного запуска SQL/phpMyAdmin.
 */
if (setting('schema_funnel') !== '2') {
    try {
        $pdo = pdo();
        // статусы → воронка (ENUM больше не ограничивает набор значений)
        $pdo->exec("ALTER TABLE crm_leads MODIFY status VARCHAR(20) NOT NULL DEFAULT 'new'");
        $pdo->exec("UPDATE crm_leads SET status='sent' WHERE status='quoted'");
        // флаги письма (на случай если mailbox.php ещё не вызывался)
        foreach ([
            "ALTER TABLE crm_messages ADD COLUMN folder VARCHAR(16) NOT NULL DEFAULT 'inbox'",
            "ALTER TABLE crm_messages ADD COLUMN is_read TINYINT NOT NULL DEFAULT 0",
            "ALTER TABLE crm_messages ADD COLUMN is_starred TINYINT NOT NULL DEFAULT 0",
            "ALTER TABLE crm_messages ADD COLUMN has_attach TINYINT NOT NULL DEFAULT 0",
        ] as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) {} }
        // канбан-поля задач
        foreach ([
            "ALTER TABLE crm_tasks ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'new'",
            "ALTER TABLE crm_tasks ADD COLUMN priority VARCHAR(10) NOT NULL DEFAULT 'normal'",
            "ALTER TABLE crm_tasks ADD COLUMN descr TEXT NULL",
        ] as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) {} }
        // выставим статус уже выполненным задачам
        try { $pdo->exec("UPDATE crm_tasks SET status='done' WHERE done=1 AND status='new'"); } catch (Throwable $e) {}
        // таблица базы знаний
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_kb (
            id INT AUTO_INCREMENT PRIMARY KEY, category VARCHAR(40) NOT NULL DEFAULT 'faq',
            title VARCHAR(255) NOT NULL, excerpt VARCHAR(500) NOT NULL DEFAULT '', body MEDIUMTEXT NULL,
            tags VARCHAR(255) NOT NULL DEFAULT '', author VARCHAR(120) NOT NULL DEFAULT '', views INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cat (category), KEY idx_views (views)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare("INSERT INTO crm_settings (skey,sval) VALUES ('schema_funnel','2')
                       ON DUPLICATE KEY UPDATE sval='2'")->execute();
    } catch (Throwable $e) { /* не блокируем админку — повторим при следующем заходе */ }
}
