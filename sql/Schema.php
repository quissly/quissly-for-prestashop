<?php

declare(strict_types=1);

/**
 * The module's tables (the CS-Cart add-on's, same names under PrestaShop's prefix): created on
 * install, dropped on uninstall. Also what upgrade scripts call to add a table a newer version needs.
 */
final class QuisslySchema
{
    /** @return array<string, string> table (without prefix) => its columns and keys */
    public static function tables(): array
    {
        return [
            'quissly_search_health' => "`lock_id` tinyint(1) unsigned NOT NULL DEFAULT '1', `last_status` smallint(5) NOT NULL DEFAULT '0',"
                . " `last_message` varchar(255) NOT NULL DEFAULT '', `last_failure_at` int(11) unsigned NOT NULL DEFAULT '0',"
                . " `last_success_at` int(11) unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`lock_id`)",
            'quissly_search_queue' => "`product_id` int(11) unsigned NOT NULL, `operation` varchar(10) NOT NULL DEFAULT 'upsert',"
                . " `created_at` int(11) unsigned NOT NULL DEFAULT '0', `attempts` smallint(5) unsigned NOT NULL DEFAULT '0',"
                . ' PRIMARY KEY (`product_id`), KEY `created_at` (`created_at`)',
            'quissly_search_synced' => "`product_id` int(11) unsigned NOT NULL, `synced_at` int(11) unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`product_id`)",
            'quissly_search_operations' => "`operation_id` varchar(64) NOT NULL, `kind` varchar(10) NOT NULL, `product_ids` mediumtext NOT NULL,"
                . " `sent_at` int(11) unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`operation_id`), KEY `sent_at` (`sent_at`)",
            'quissly_search_state' => '`name` varchar(64) NOT NULL, `value` mediumtext NOT NULL, PRIMARY KEY (`name`)',
            'quissly_search_log' => "`log_id` int(11) unsigned NOT NULL AUTO_INCREMENT, `created_at` int(11) unsigned NOT NULL DEFAULT '0',"
                . " `message` varchar(1000) NOT NULL DEFAULT '', PRIMARY KEY (`log_id`)",
            'quissly_search_tokens' => "`token` char(36) NOT NULL, `product_ids` text NOT NULL, `created_at` int(11) unsigned NOT NULL DEFAULT '0',"
                . ' PRIMARY KEY (`token`), KEY `created_at` (`created_at`)',
            'quissly_search_rate' => "`bucket` char(40) NOT NULL, `window_start` int(11) unsigned NOT NULL DEFAULT '0',"
                . " `hits` int(11) unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`bucket`)",
            'quissly_search_ids' => '`quissly_id` char(36) NOT NULL, `product_id` int(11) unsigned NOT NULL, PRIMARY KEY (`quissly_id`), KEY `product_id` (`product_id`)',
            'quissly_search_events' => "`event_id` int(11) unsigned NOT NULL AUTO_INCREMENT, `body` mediumtext NOT NULL,"
                . " `attempts` smallint(5) unsigned NOT NULL DEFAULT '0', `created_at` int(11) unsigned NOT NULL DEFAULT '0',"
                . " `next_at` int(11) unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`event_id`), KEY `next_at` (`next_at`)",
        ];
    }

    public static function install(): bool
    {
        $db = Db::getInstance();
        foreach (self::tables() as $table => $columns) {
            $charset = $table === 'quissly_search_events' ? 'utf8mb4' : 'utf8';
            if (!$db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . $table . '` (' . $columns . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=' . $charset)) {
                return false;
            }
        }

        return true;
    }

    public static function uninstall(): bool
    {
        $db = Db::getInstance();
        foreach (array_keys(self::tables()) as $table) {
            $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
        }

        return true;
    }
}
