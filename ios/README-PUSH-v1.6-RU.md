# DashBoard iOS v1.6 — Firebase Cloud Messaging / APNs

Использует существующий Firebase-проект `dashboad-uz17`, Bundle ID `ru.freepbx.qstat.ios`, проект iOS v1.5. Внешний вид приложения не изменён.

## Что добавлено
- `GoogleService-Info.plist` встроен в целевой bundle (прислан пользователем).
- FirebaseCore и FirebaseMessaging через Swift Package Manager (загрузка Xcode при открытии проекта).
- Запрос разрешения на уведомления, APNs device token, FCM token, автоматический POST `api.php?action=register_push` с Bearer auth.
- Устойчивая к перезапускам установка `installation_id` в UserDefaults, повторная регистрация при запуске и обновлении токена.
- Нажатие на push `type=missed_call` или `screen=incoming&status=missed` открывает входящие и статус `missed_no_callback`.
- Сохранены все функции v1.5, внешний вид, Bundle ID.

## Xcode
1. Откройте `FreePBXQStat.xcodeproj`. Xcode автоматически разрешит и скачает Firebase SPM-пакеты (нужен интернет).
2. Выберите свою Apple Developer Team в Signing & Capabilities. Provisioning Profile должен включать Push Notifications.
3. Убедитесь, что разрешена возможность `Push Notifications` (entitlement `aps-environment`). Для TestFlight требуется APNs Production; в Release настройке уже указан `production`, в Debug — `development`.
4. Соберите на **реальном iPhone** (симулятор для этой проверки не используем). После подключения к FreePBX разрешите уведомления.
5. Для диагностики смотрите Xcode console: `DashBoard push registration failed`, `DashBoard APNs registration`.
6. Проверьте запись `platform=ios` в `cc_push_devices` и только потом тест через `push_test.php`.

## Требования сервера
- `api.php?action=register_push` принимает Bearer токен, JSON `installation_id`, `platform=ios`, `fcm_token`.
- `push_worker.php` отправляет через FCM v1. Firebase Console должен содержать APNs .p8 ключ для Production (TestFlight) и Development (Debug).
- Самоподписанный TLS сертификат FreePBX может приводить к ошибке регистрации/подключения из iOS; установите доверенный сертификат или настройте доверие к своей CA. Не отключайте проверку TLS.

## Ограничения проверки
Здесь проверены структура исходников и файлы конфигурации; компиляция Xcode, получение FCM/APNs токена и фактическая доставка ещё не выполнялись.
