<?php

// Quissly for PrestaShop - ru texts of the PrestaShop module itself: its own, and the CS-Cart
// add-on's texts that named CS-Cart, reworded (texts/ru.php keeps CS-Cart's).
return [
    'quissly_search.dash_gate_blocked' => 'Последняя полная синхронизация завершилась без подтверждения хотя бы одного товара от Quissly, поэтому поиск остаётся встроенным поиском PrestaShop. Найдите причину в журнале синхронизации и запустите синхронизацию снова.',
    'quissly_search.dash_open_settings' => 'Настройки модуля',
    'quissly_search.dash_search_inactive' => 'Магазин использует встроенный поиск PrestaShop, потому что:',
    'quissly_search.feature_not_built' => 'в модуле PrestaShop пока нет: сохраняется, но ни на что не влияет, пока не появится',
    'quissly_search.no' => 'Нет',
    'quissly_search.not_configured' => 'Не заданы в config/defines_custom.inc.php',
    'quissly_search.origin_native' => 'Результаты поиска PrestaShop',
    'quissly_search.panel_missing_account' => 'Панель не может выполнить вход автоматически: в config/defines_custom.inc.php для этого магазина нет QUISSLY_PROJECT_ID или QUISSLY_ACCOUNT_EMAIL. Откройте её в новой вкладке и войдите в свой аккаунт Quissly.',
    'quissly_search.panel_platform_unsupported' => 'Quissly пока не умеет автоматически входить в панель для магазинов PrestaShop. Откройте её в новой вкладке и войдите в свой аккаунт Quissly.',
    'quissly_search.reason_credentials_not_configured' => 'Учётные данные не заданы в config/defines_custom.inc.php',
    'quissly_search.reason_initial_sync_pending' => 'Quissly ещё не подтвердил первую синхронизацию каталога, поэтому до этого магазин использует встроенный поиск PrestaShop.',
    'quissly_search.save' => 'Сохранить',
    'quissly_search.setting_tooltip.enable_quick' => 'Подсказки товаров, пока покупатель печатает: под строкой окна поиска или под полем поиска темы. Названия, изображения и цены берутся из вашего магазина. Quick также должен быть включён в вашем аккаунте Quissly.',
    'quissly_search.setting_tooltip.quick_style' => 'Компактный список названий или крупные карточки с изображениями и ценами. На телефоне всегда список.',
    'quissly_search.setting_tooltip.search_interception' => 'Если выключено, магазин использует встроенный поиск PrestaShop. Подключение и синхронизация каталога не затрагиваются.',
    'quissly_search.setting_tooltip.update_check_every' => 'Как часто модуль ищет новую версию Quissly и устанавливает её. Никогда: без автоматических обновлений.',
    'quissly_search.setup_connected_config' => 'Этот магазин подключён к Quissly с учётными данными из config/defines_custom.inc.php (окружение: [env]). Подключение в один клик не нужно.',
    'quissly_search.setup_step_key' => 'Модуль создаёт ключ подписи магазина и регистрирует его в Quissly. Ключи хранятся в зашифрованном виде; ничего копировать или вставлять не нужно.',
    'quissly_search.setup_undecryptable' => 'Этот магазин был подключён к Quissly, но сохранённые учётные данные больше не читаются: cookie_key в app/config/parameters.php с тех пор изменился. Верните прежний cookie_key. Повторное подключение создало бы второй аккаунт Quissly, поэтому здесь оно не предлагается - если старый ключ утерян, обратитесь в поддержку Quissly.',
    'quissly_search.su_live_safety_text' => 'Если Quissly не сможет ответить, ответит поиск PrestaShop.',
    'quissly_search.test_connection_auth_fail' => 'Авторизация в Quissly не удалась (HTTP [status]). Проверьте bearer-токен, закрытый ключ и окружение в config/defines_custom.inc.php.',
    'quissly_search.dash_cron_missing' => 'Автоматическая синхронизация на сервере не настроена. Изменения товаров ставятся в очередь и отправляются каждые несколько минут, пока покупатели посещают магазин, когда кто-то открывает любую страницу этой админки, или сразу, когда вы нажимаете кнопку синхронизации выше. Чтобы они уходили вовремя, даже когда магазин никто не посещает, добавьте эту строку в crontab сервера или в cron-задания панели хостинга (каждые 5 минут):',
];
