# Firebase + Apple Developer — Push для iOS, TestFlight и Production

Прежде чем приступать, необходимы Apple Developer Program (владелец/команда), реальный iPhone и Xcode на Mac. В примере Bundle ID **`ru.freepbx.qstat.ios`**, но при независимом форке используйте свой уникальный Bundle ID и согласуйте его во всех системах.

## A. Apple Developer: App ID и Push Notifications

1. Войдите в https://developer.apple.com/account/ → Certificates, Identifiers & Profiles → Identifiers.
2. Найдите зарегистрированный App ID для `ru.freepbx.qstat.ios`; если его нет, создайте новый **App ID (App)** с явным Bundle ID. Для существующей инсталляции не меняйте Bundle ID.
3. Убедитесь, что у App ID включена capability **Push Notifications**.
4. В Xcode откройте `FreePBXQStat.xcodeproj`, выберите Target → Signing & Capabilities, вашу Apple Team и Push Notifications. Для корректной подписи используйте автоматически управляемый provisioning profile либо корректный собственный.

## B. Apple Developer: APNs Authentication Key (.p8)

1. Откройте Certificates, Identifiers & Profiles → **Keys** → **+**.
2. Введите описание, например `DashBoard APNs`.
3. Отметьте **Apple Push Notifications service (APNs)**; если интерфейс предложит область применения/окружение, выберите подходящее для Sandbox и Production. Возможности интерфейса могут изменяться.
4. Нажмите Continue → Register → Download. Сохраните приватный `.p8` в безопасном хранилище: повторное скачивание может быть недоступно.
5. Запишите **Key ID** созданного ключа; **Team ID** найдёте в разделе Membership вашей команды. `.p8`, приватные ключи, пароли и service-account JSON не публикуйте.

**Окружения:** установка development-сборки через Xcode обычно использует APNs Sandbox; TestFlight и App Store — Production. В отличие от certificate-based подхода token-based APNs key может быть использован для обеих сред при поддержке соответствующей конфигурации. Проверьте в Firebase запись ключа в требуемом разделе.

## C. Firebase: зарегистрировать iOS

1. Откройте https://console.firebase.google.com/ → нужный проект (для исходного работающего стенда `dashboad-uz17`).
2. Project settings (шестерёнка) → **General** → **Your apps** → **Add app** → значок Apple/iOS.
3. В **Apple bundle ID** укажите `ru.freepbx.qstat.ios`; Nickname можно указать `DashBoard iOS`; App Store ID пока необязателен.
4. Нажмите **Register app** и скачайте **`GoogleService-Info.plist`**.
5. Положите plist в целевой iOS app target, убедитесь, что файл добавлен в Copy Bundle Resources / target membership. Файл должен соответствовать Bundle ID.
6. Пункты Add Firebase SDK и Initialization: в исходной версии v1.6 FirebaseCore/FirebaseMessaging уже подключены через Swift Package Manager и `UIApplicationDelegateAdaptor`; в Xcode выполните Resolve Package Versions и проверьте сборку.

## D. Firebase: загрузка APNs-ключа

1. Project settings → **Cloud Messaging**.
2. Найдите **Apple app configuration** → ваш iOS Bundle ID → **APNs authentication key**.
3. Нажмите Upload, укажите **`.p8`**, **Key ID**, **Team ID**.
4. Если консоль отдельно показывает Development/Sandbox и Production, убедитесь, что Production настроен для TestFlight, а Development — для debug-сборок. При необходимости загрузите подходящий токеновый ключ в каждый предусмотренный раздел.
5. Сохраните изменения. Firebase не может самостоятельно подтвердить доставку на незарегистрированное устройство: следующим этапом нужен реальный iPhone.

## E. Проверка на iPhone и сервере

1. На реальном iPhone установите собранный DashBoard iOS v1.6, разрешите уведомления и подключитесь к API по HTTPS.
2. Сборка получает APNs token, Firebase FCM token и отправляет `POST /qstat/api.php?action=register_push` с JSON `installation_id`, `platform=ios`, `fcm_token`. `installation_id` сохраняется между запусками.
3. В MySQL: `SELECT id,platform,enabled,last_seen_at FROM cc_push_devices WHERE platform='ios';`.
4. В командной строке сервера с настроенным сервисным аккаунтом: `php push_test.php --list`, затем `php push_test.php --device=ID --send` (ID из таблицы).
5. Сверните приложение/заблокируйте iPhone; дождитесь уведомления; нажмите и убедитесь в открытии **Входящие → Пропущенные**. Затем проверьте настоящий пропущенный звонок через очередь FreePBX.
6. Для TestFlight создайте запись приложения в App Store Connect и загрузите Release Archive. **TestFlight использует Production APNs**, даже если само распространение тестовое. Внутренние тестировщики должны иметь доступ App Store Connect; внешние проходят соответствующий review.

## Ошибки

- Нет записи в `cc_push_devices`: разрешение уведомлений, APNs capability/entitlement, Firebase plist, ошибка TLS/401 на API, проверьте логи Xcode.
- `THIRD_PARTY_AUTH_ERROR` / ошибка APNs от FCM: перепроверьте `.p8`, Key ID, Team ID, Bundle ID, окружение и разрешения.
- `UNREGISTERED`: повторная регистрация FCM-токена; устаревший токен мог быть отключён worker.
- Работает в Debug, не работает TestFlight: прежде всего проверяйте настройку **Production APNs** и Release подпись.

Сверяйте названия кнопок Firebase/Apple с текущим интерфейсом: они могут меняться со временем.

## Официальная документация

- Firebase iOS FCM: https://firebase.google.com/docs/cloud-messaging/ios/get-started
- Firebase Apple setup: https://firebase.google.com/docs/ios/setup
- Apple APNs authentication keys: https://developer.apple.com/help/account/capabilities/communicate-with-apns-using-authentication-tokens
- Apple create private key: https://developer.apple.com/help/account/keys/create-a-private-key/
