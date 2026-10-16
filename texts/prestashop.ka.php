<?php

// Quissly for PrestaShop - ka texts of the PrestaShop module itself: its own, and the CS-Cart
// add-on's texts that named CS-Cart, reworded (texts/ka.php keeps CS-Cart's).
return [
    'quissly_search.dash_gate_blocked' => 'ბოლო სრული სინქრონიზაცია ისე დასრულდა, რომ Quissly-მ არცერთი პროდუქტი არ დაადასტურა, ამიტომ ძიება PrestaShop-ის ჩაშენებულ ძიებაზე რჩება. მიზეზი სინქრონიზაციის ჟურნალში ნახეთ და სინქრონიზაცია თავიდან გაუშვით.',
    'quissly_search.dash_open_settings' => 'მოდულის პარამეტრები',
    'quissly_search.dash_search_inactive' => 'მაღაზია PrestaShop-ის ჩაშენებულ ძიებას იყენებს, რადგან:',
    'quissly_search.feature_not_built' => 'PrestaShop-ის მოდულში ჯერ არ არის: ინახება, მაგრამ გამოსვლამდე არაფერს ცვლის',
    'quissly_search.no' => 'არა',
    'quissly_search.not_configured' => 'config/defines_custom.inc.php-ში მითითებული არ არის',
    'quissly_search.origin_native' => 'შედეგები PrestaShop-ის ძიებიდან',
    'quissly_search.panel_missing_account' => 'პანელი ავტომატურად ვერ შეგიყვანთ: config/defines_custom.inc.php-ში ამ მაღაზიისთვის QUISSLY_PROJECT_ID ან QUISSLY_ACCOUNT_EMAIL არ არის. გახსენით ახალ ჩანართში და შედით თქვენი Quissly-ს ანგარიშით.',
    'quissly_search.panel_platform_unsupported' => 'Quissly ჯერ ვერ ახერხებს PrestaShop მაღაზიების პანელში ავტომატურ შეყვანას. გახსენით ახალ ჩანართში და შედით თქვენი Quissly-ს ანგარიშით.',
    'quissly_search.reason_credentials_not_configured' => 'კავშირის მონაცემები config/defines_custom.inc.php-ში არ არის მითითებული',
    'quissly_search.reason_initial_sync_pending' => 'კატალოგის პირველი სინქრონიზაცია Quissly-ს ჯერ არ დაუდასტურებია, ამიტომ მანამდე მაღაზია PrestaShop-ის ჩაშენებულ ძიებას იყენებს.',
    'quissly_search.save' => 'შენახვა',
    'quissly_search.setting_tooltip.enable_quick' => 'პროდუქტების შემოთავაზებები, სანამ მყიდველი წერს: ძიების ფანჯრის ველის ქვეშ ან თემის საძიებო ველის ქვეშ. სახელები, სურათები და ფასები თქვენი მაღაზიიდან მოდის. Quick თქვენს Quissly ანგარიშზეც უნდა იყოს ჩართული.',
    'quissly_search.setting_tooltip.quick_style' => 'სახელების კომპაქტური სია, ან უფრო დიდი ბარათები სურათებით და ფასებით. ტელეფონზე ყოველთვის სიაა.',
    'quissly_search.setting_tooltip.search_interception' => 'გამორთვისას მაღაზია PrestaShop-ის ჩაშენებულ ძიებას იყენებს. კავშირი და კატალოგის სინქრონიზაცია ამით არ იცვლება.',
    'quissly_search.setting_tooltip.update_check_every' => 'რამდენად ხშირად ეძებს მოდული Quissly-ის ახალ ვერსიას და აყენებს მას. არასდროს: ავტომატური განახლებების გარეშე.',
    'quissly_search.setup_connected_config' => 'ეს მაღაზია Quissly-სთან config/defines_custom.inc.php-ში მითითებული მონაცემებით არის დაკავშირებული (გარემო: [env]). ერთი ღილაკით დაკავშირება საჭირო არ არის.',
    'quissly_search.setup_step_key' => 'მოდული მაღაზიის ხელმოწერის გასაღებს ქმნის და Quissly-ში არეგისტრირებს. გასაღებები დაშიფრულად ინახება; არაფრის კოპირება ან ჩასმა არ მოგიწევთ.',
    'quissly_search.setup_undecryptable' => 'ეს მაღაზია Quissly-სთან იყო დაკავშირებული, მაგრამ შენახული მონაცემების წაკითხვა აღარ ხერხდება: app/config/parameters.php-ში cookie_key მას შემდეგ შეიცვალა. აღადგინეთ წინა cookie_key. თავიდან დაკავშირება Quissly-ს მეორე ანგარიშს შექმნიდა, ამიტომ აქ არ არის შემოთავაზებული - თუ ძველი გასაღები დაკარგულია, მიმართეთ Quissly-ს მხარდაჭერას.',
    'quissly_search.su_live_safety_text' => 'თუ Quissly ვერ უპასუხებს, PrestaShop-ის ძიება უპასუხებს.',
    'quissly_search.test_connection_auth_fail' => 'Quissly-ში ავტორიზაცია ვერ მოხერხდა (HTTP [status]). შეამოწმეთ bearer ტოკენი, პირადი გასაღები და გარემო config/defines_custom.inc.php-ში.',
    'quissly_search.dash_cron_missing' => 'სერვერზე ავტომატური სინქრონიზაცია არ არის დაყენებული. პროდუქტების ცვლილებები რიგში დგება და იგზავნება ყოველ რამდენიმე წუთში, სანამ მყიდველები მაღაზიას სტუმრობენ, როცა ვინმე ამ ადმინის რომელიმე გვერდს გახსნის, ან მაშინვე, როცა ზემოთ სინქრონიზაციის ღილაკს დააჭერთ. რომ დროულად წავიდეს მაშინაც, როცა მაღაზიას არავინ სტუმრობს, ეს ხაზი დაამატეთ სერვერის crontab-ში ან ჰოსტინგის პანელის cron jobs-ში (ყოველ 5 წუთში):',
];
