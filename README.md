# DashBoard for FreePBX

DashBoard — мобильная и серверная система статистики звонков очередей FreePBX / Asterisk: входящие, исходящие, пропущенные, обратные звонки, запись разговоров и уведомления о пропущенных вызовах.

## Компоненты

- **Android v2.4** — Java, Firebase Cloud Messaging, выбор агента для звонка клиенту.
- **iOS v1.6** — SwiftUI, Firebase Messaging и Apple Push Notification service (APNs).
- **FreePBX QStat** — PHP 5.6, Asterisk AMI, MariaDB/MySQL, синхронизация входящих, исходящих и callbacks.
- **Push** — `cc_push_devices`, `cc_push_events`, `cc_push_deliveries`, FCM HTTP v1 и APNs.

## Исходники и документация

Комплект подготовлен к публичной публикации с исключением секретов: `qstat.inc.php`, Firebase service account JSON, APNs `.p8`, ключей подписи и локальных конфигураций. **Исходные файлы пока загружаются в этот репозиторий отдельно от README.**

Документация включает установку сервера и MariaDB, cron 15 секунд, настройку Firebase для Android/iOS, Apple Developer, сборки Xcode и Android Studio и устранение ошибок.

### Установка

1. Скопировать `examples/qstat.inc.php.example` в локальный `server/qstat.inc.php` и заполнить своими значениями.
2. Установить SQL из `database/schema/` на **новую** БД; использовать резервную копию перед любой операцией на действующем сервере. В установочных файлах нет `DROP TABLE`.
3. Разместить PHP-скрипты QStat на сервере, настроить права, cron и FCM service account вне web-root.
4. Для Android скачать свой `google-services.json`; для iOS — `GoogleService-Info.plist`. Настроить APNs Authentication Key в Firebase Cloud Messaging.
5. Проверить регистрацию устройства и отправку тестового push.

## Совместимость

Исходные идентификаторы сохранены: Android `com.freepbx.qstat`, iOS `ru.freepbx.qstat.ios`.

## Текущие ограничения

В действующей версии `call.php` и `recordings.php` используются как отдельные endpoints. Их перенос в авторизованный API запланирован для следующего релиза. Никогда не публикуйте реальные учетные данные или ключи.

## Статус

Репозиторий открыт; публикация полного набора исходников и документации выполняется поэтапно.
