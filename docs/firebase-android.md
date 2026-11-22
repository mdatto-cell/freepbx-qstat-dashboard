# Firebase — Android (пошаговая настройка)

1. Откройте https://console.firebase.google.com/ и создайте Firebase-проект или выберите уже существующий. Если разворачиваете свою инсталляцию, используйте собственный проект, а не исходный `dashboad-uz17`.
2. В Project settings → General → Your apps добавьте **Android**. В поле **Android package name** введите `com.freepbx.qstat`. Параметр должен совпадать с `applicationId` в `app/build.gradle`.
3. Скачайте `google-services.json` и положите в `android/.../app/google-services.json`. Не используйте файл другого Firebase-проекта.
4. В Android-проекте уже подключены Gradle plugin `com.google.gms.google-services`, Firebase BOM и `firebase-messaging`; проверьте синхронизацию Gradle в Android Studio.
5. При первом запуске Android 13+ приложение запросит разрешение на уведомления (`POST_NOTIFICATIONS`); разрешите его.
6. После ввода URL каталога сервера и API Bearer-токена приложение отправляет FCM-токен через `POST api.php?action=register_push`; `platform=android`.
7. Проверьте запись в `cc_push_devices` и отправку тестового сообщения с сервера: `php push_test.php --list`, затем `php push_test.php --device=ID --send` при наличии безопасно настроенной переменной `QSTAT_FIREBASE_SERVICE_ACCOUNT`.
8. Нажатие на уведомление `com.freepbx.qstat.OPEN_MISSED` должно открыть входящие пропущенные вызовы.

Для работы сервера по FCM HTTP v1 создайте в Firebase/Google Cloud **service account**, обладающий необходимым правом на отправку сообщений. JSON с приватным ключом храните **только на сервере вне web root** с правами на чтение сервисным пользователем. В `push_common.php` используется OAuth2 JWT с scope `https://www.googleapis.com/auth/firebase.messaging`, URL `https://oauth2.googleapis.com/token` и FCM v1 `https://fcm.googleapis.com/v1/projects/PROJECT_ID/messages:send`.

Для сервера с Nginx убедитесь, что API доступен по HTTPS с доверенным сертификатом; проверку TLS в Android не отключайте. Настройка `android:usesCleartextTraffic="true"` присутствует в исходном AndroidManifest и отмечена как риск для финального релиза.

## Официальная документация

- FCM HTTP v1: https://firebase.google.com/docs/cloud-messaging/send/v1-api
- Firebase server environment: https://firebase.google.com/docs/cloud-messaging/server-environment
