# Развёртывание DashBoard

DashBoard включает серверную часть FreePBX QStat (PHP 5.6, MariaDB), Android v2.4 и iOS v1.6.

## Сервер
1. Создайте резервные копии действующих PHP-скриптов и БД.
2. На чистой БД импортируйте файлы из `database/schema/` — установочные схемы не содержат `DROP TABLE`.
3. Разместите PHP-скрипты на FreePBX, создайте локальный `qstat.inc.php` на основе `examples/qstat.inc.php.example`.
4. Настройте HTTPS, Bearer-токены API, доступ к Asterisk AMI и синхронизацию звонков.
5. Сохраните действующий cron; альтернативный вариант с `flock` и интервалом 15 секунд представлен отдельно.
6. Сервисный JSON Firebase Admin SDK храните вне веб-каталога.

## Firebase Android
В Firebase Console создайте Android-приложение с пакетом `com.freepbx.qstat`, скачайте `google-services.json` в `android/app/`, настройте Firebase Cloud Messaging. Соберите APK в Android Studio.

## Firebase iOS и Apple Developer
В том же Firebase-проекте зарегистрируйте iOS-приложение с Bundle ID `ru.freepbx.qstat.ios`, скачайте `GoogleService-Info.plist` в iOS target. В Apple Developer создайте APNs Authentication Key (.p8), сохраните Key ID и Team ID. В Firebase Project settings → Cloud Messaging → Apple app configuration загрузите ключ для нужных сред. TestFlight использует APNs Production. В Xcode активируйте Push Notifications и подпись собственной Team.

## Проверка push
Проверьте появление iOS/Android устройств в `cc_push_devices`. Запустите `php push_test.php --device=ID --send`, затем выполните реальный пропущенный звонок и проверьте `cc_push_events` и `cc_push_deliveries`. По нажатию на уведомление приложение должно открыть входящие → пропущенные.

## Следующий релиз
Вынести функции `call.php` и `recordings.php` в авторизованный `api.php`. В текущем релизе сохраняется рабочая архитектура.
