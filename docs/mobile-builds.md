# Сборка Android и iOS

## Android v2.4

1. Распакуйте `FreePBXQStat-Android-v2.4-source-and-server(1).zip`, откройте `FreePBXQStat-Android/` в Android Studio.
2. Замените `app/google-services.json` на конфиг вашего Firebase-проекта при независимой установке.
3. Синхронизируйте Gradle; проект использует `compileSdk 35`, `targetSdk 34`, `minSdk 26` и Firebase Messaging.
4. Соберите подписанный APK/AAB своим ключом; для обновления установленных приложений используйте прежний signing key.
5. `applicationId` — `com.freepbx.qstat`. Переименование ярлыка `DashBoard` не меняет идентификатор.
6. При подключении вводится URL каталога `/qstat`, а не `/qstat/api.php`; серверный заголовок берётся из `$MobileName`, иначе `DashBoard`.
7. Переключение темы, динамический список агентов и набор номера через `call.php` реализованы в приложении. Записи продолжительностью 00:00 не проигрываются.

## iOS v1.6

1. Распакуйте `DashBoard-iOS-v1.6-FCM-Push-source(1).zip`, откройте `FreePBXQStat-iOS/FreePBXQStat.xcodeproj` в Xcode на Mac.
2. Убедитесь в наличии `GoogleService-Info.plist`, подключённых FirebaseCore/Messaging, выборе Apple Team, подписанных provisioning profiles и Push Notifications.
3. Сборка ориентирована на iOS 17+, использует Bundle ID `ru.freepbx.qstat.ios`, version 1.6 build 6.
4. Задайте адрес `/qstat` и API Bearer token, проверьте работу `action=config`, `action=agents`, получение звонков, записи, отправку звонка и push.
5. Debug подписывается для Sandbox APNs, Release/TestFlight — для Production APNs при корректной автоматической подписи.

Не утверждаем, что приложения повторно собирались в этой среде: это описание по исходникам, предоставленным пользователем. Работоспособность на реальных устройствах ранее была подтверждена пользователем в беседе.
