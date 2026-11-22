# DashBoard for FreePBX — документация проекта

**Основание:** предоставленные архивы `DashBoard-iOS-v1.6-FCM-Push-source(1).zip`, `FreePBXQStat-Android-v2.4-source-and-server(1).zip`, `qstat.zip` (22 ноября 2026 г.). Исходники Android, iOS, серверной части и установочные SQL-схемы опубликованы в этом открытом репозитории.

DashBoard отображает статистику звонков очередей FreePBX/Asterisk, входящие/исходящие вызовы и статусы обратных звонков; позволяет воспроизводить доступные записи и инициировать исходящий звонок через выбранного агента. Пропущенные звонки отправляются на Android и iOS через Firebase Cloud Messaging; iOS получает их через APNs. Нажатие на уведомление открывает входящие пропущенные вызовы.

## Состав и проверенные версии

| Компонент | Проверено в архиве |
|---|---|
| Android | Java, `com.freepbx.qstat`, версия 2.4 / code 24, minSdk 26, targetSdk 34, Firebase Messaging |
| iOS | SwiftUI, `ru.freepbx.qstat.ios`, версия 1.6 / build 6, iOS 17+, Firebase SPM |
| Сервер | PHP 5.6-совместимый код, PDO, cURL, OpenSSL, MySQL/MariaDB, FreePBX/Asterisk, AMI |
| База данных | `cc_calls_report`, `cc_etl_state`, `cc_outbound_calls`, `cc_push_devices`, `cc_push_events`, `cc_push_deliveries` |
| Веб-панель | `index.php`, `dashboard.css` |

## Изменения этой редакции

- Добавлены шесть SQL-схем в `database/schema/` без `DROP TABLE`; они предназначены для первичной установки и **не** обновляют существующие таблицы.
- Существующий рабочий cron на FreePBX **не изменяется**. Отдельно приложен альтернативный пример `examples/qstat.cron.example`.
- В этой версии вызовы `call.php` и `recordings.php` сохраняются без изменений. Перенос в `api.php` с использованием его авторизации — задача следующего релиза.

## Быстрый маршрут установки

1. Прочитайте [аудит безопасности](audits/SECURITY-REVIEW.md) — исходный `qstat.inc.php` нельзя публиковать.
2. Разверните сервер по [инструкции](docs/installation.md) на тестовом стенде.
3. Настройте Firebase FCM / [Android](docs/firebase-android.md) / [Apple Developer + iOS](docs/firebase-apple-ios.md).
4. Соберите приложения: [Android и iOS](docs/mobile-builds.md).
5. Проверьте регистрацию устройств и push по [диагностике](docs/troubleshooting.md).
6. Перед GitHub выполните [проверку публикации](docs/github-publication.md).

## Архитектура

```text
Asterisk queuelog + cdr
      | sync_queue_report-new.php / sync_callbacks-new.php / sync_outbound_calls-new.php
      v
cc_calls_report + cc_outbound_calls + cc_etl_state
      |                          |
      | api.php                  | missed_no_callback
      v                          v
Android / iOS              cc_push_events
      |                          | push_worker.php
      | register_push            v
      +--> cc_push_devices --> cc_push_deliveries --> FCM HTTP v1
                                              |             |
                                           Android       APNs -> iOS

Мобильный звонок: Android/iOS -> call.php -> Asterisk AMI Originate
Прослушивание: Android/iOS -> recordings.php (план: перенести в api.php в следующей версии)
```

**Замечание:** исходный `qstat.zip` включает рабочую конфигурацию; намеренно **не вложен** в этот архив. Приватные ключи, токены и пароли сюда не копировались. Данные `.plist` и `google-services.json` обычно идентифицируют Firebase-проект и не являются сервисными приватными ключами, однако перед релизом всё равно нужно проверить конфигурацию и домены.


## Source layout

- `android/`: DashBoard Android v2.4, original package `com.freepbx.qstat`.
- `ios/`: DashBoard iOS v1.6, original bundle ID `ru.freepbx.qstat.ios`.
- `server/`: current FreePBX QStat PHP scripts (PHP 5.6 compatible).
- `database/schema/`: installation-only SQL scripts with **no `DROP TABLE` commands**. Do not apply over production without backup.
- `docs/`: installation, Firebase, APNs / Apple Developer and troubleshooting guides.
- `examples/`: sample QStat configuration and optional 15-second cron alternative.

### Before building

1. Copy `examples/qstat.inc.php.example` to `server/qstat.inc.php` and set local credentials (never commit that file).
2. Download **your own** `google-services.json` from Firebase into `android/app/`.
3. Download **your own** `GoogleService-Info.plist` into `ios/FreePBXQStat/`.
4. Store Firebase service-account credentials **outside** the web root.
5. Keep current cron schedule as deployed; `examples/qstat.cron.example` is an optional alternative.

The current releases retain `call.php` and `recordings.php`. Moving those routes behind authenticated `api.php` is a **future version** task.

### Security notice

The original server configuration, service-account secrets, and signing keys are not included. Prior deployment credentials should be rotated if they were ever shared or committed publicly.
