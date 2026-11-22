# Диагностика и безопасный тест

**1. 401 с правильным Bearer**: убедитесь, что прокси и Apache пробрасывают `Authorization`. Для Apache в некоторых конфигурациях требуется `SetEnvIfNoCase Authorization "^(.+)$" HTTP_AUTHORIZATION=$1`. При этом избегайте токена в query string.

**2. 400 при `register_push`**: `fcm_token` должен быть одной строкой без пробелов/переносов, `installation_id` длиной 8–128 допустимых ASCII-символов, `platform` — `android`/`ios`. Снимите `curl -f`, чтобы увидеть JSON с ошибкой.

**3. Push работает вручную, но не при реальном пропущенном звонке**:

```sql
SELECT id,callid,status,created_at FROM cc_calls_report ORDER BY id DESC LIMIT 10;
SELECT id,callid,event_type,created_at FROM cc_push_events ORDER BY id DESC LIMIT 10;
SELECT id,event_id,device_id,state,last_error FROM cc_push_deliveries ORDER BY id DESC LIMIT 10;
```

Если звонок есть, но события нет, проверьте `sync_queue_report-new.php`, его курсор `cc_etl_state`, условие `missed_no_callback`, и наличие сверки за 10 минут. Не создавайте SQL-события массово, особенно на рабочем сервере.

**4. Событие есть, доставки нет**: проверьте `cc_push_devices.enabled`, cron `push_worker.php`, переменную `QSTAT_FIREBASE_SERVICE_ACCOUNT`, файл ключа и выход в Google по HTTPS. У worker есть lock и повторные попытки; ошибки пишутся в PHP/cron логи и поле `last_error`.

**5. FCM 200, iOS не отображает**: проверьте разрешения уведомлений на телефоне, APNs key в Firebase, Team ID, Bundle ID, окружение Debug/Release, актуальный FCM token. На заблокированном устройстве тестируется надёжнее, чем при открытом приложении.

**6. Нет записи или 00:00**: запись может отсутствовать; UI должен не показывать кнопку плеера. Сервис `recordings.php` требует отдельного аудита авторизации перед интернет-публикацией.

**7. Безопасность перед показом руководителям**: используйте тестовых клиентов либо согласованные данные; ограничьте право инициировать звонки. Проверьте отсутствие реальных секретов в публичных конфигурациях.
