# Установка сервера FreePBX QStat

Инструкция по фактической структуре архива `qstat.zip`. Команды — примеры для CentOS/FreePBX и каталога `/var/www/html/qstat`. Все операции сначала проверяйте на тестовой копии. **В установочных схемах этого комплекта команды `DROP TABLE IF EXISTS` удалены.** Рабочая БД и действующее расписание cron не изменялись.

## Зависимости и подготовка

- Asterisk/FreePBX с таблицами `queuelog` и `cdr`, настроенным доступом через PDO MySQL/MariaDB; PHP CLI + Apache PHP, расширения `pdo_mysql`, `mysqli`, `curl`, `openssl`, `json`, при необходимости `mbstring`.
- AMI-учётная запись с минимально необходимым разрешением `Originate`; убедитесь, что AMI слушает только доверенный интерфейс.
- HTTPS перед Nginx с сертификатом от доверенного CA и передачей заголовка `Authorization` до PHP; доступ из интернета по HTTP должен быть закрыт.
- Установленный `flock` (`util-linux`) и cron; для исходящего доступа сервера открыты `oauth2.googleapis.com` и `fcm.googleapis.com` по HTTPS.

## База

В исходном архиве **ровно 6** файлов схем; их копии **без `DROP TABLE`** приложены в [`../database/schema/`](../database/schema/):
`cc_calls_report.sql`, `cc_etl_state.sql`, `cc_outbound_calls.sql`, `cc_push_devices.sql`, `cc_push_events.sql`, `cc_push_deliveries.sql`.

На **новой базе** импортируйте безопасные копии из каталога `database/schema` по одному, например:

```bash
mysql -u DB_USER -p DB_NAME < cc_calls_report.sql
mysql -u DB_USER -p DB_NAME < cc_etl_state.sql
mysql -u DB_USER -p DB_NAME < cc_outbound_calls.sql
mysql -u DB_USER -p DB_NAME < cc_push_devices.sql
mysql -u DB_USER -p DB_NAME < cc_push_events.sql
mysql -u DB_USER -p DB_NAME < cc_push_deliveries.sql
```

Если таблица уже существует, команда `CREATE TABLE` выдаст ошибку, но не удалит существующие данные. Это **не миграции**: при обновлении существующей БД сравните `SHOW CREATE TABLE` с файлами и применяйте только точечные `ALTER TABLE`, с резервной копией. Схемы не содержат автоматически восстановленных данных. `cc_etl_state` хранит курсоры синхронизации, например `queue_log_max_id`: не удаляйте их на рабочей системе.

## Asterisk

В `extconfig_custom.conf` (проверьте путь в используемой версии FreePBX) из исходного `INSTALL.txt`:

```ini
[settings]
queue_log => odbc,asteriskcdrdb,queuelog
```

В `logger_general_custom.conf`:

```ini
queue_log_to_file = yes
queue_adaptive_realtime = yes
```

Требуется настроенный DSN `asteriskcdrdb` для ODBC и доступность `queuelog` из базы, указанной в PHP DSN. Сделайте резервные копии конфигурации, проверьте синтаксис и перезагрузите соответствующие модули только в запланированное окно обслуживания. Исходный `INSTALL.txt` предлагает `module reload res_odbc.so`, `module reload logger`, `core restart now`, но **restart Asterisk прервёт действующие вызовы** — не выполняйте его без оценки последствий.

Для ночных IVR сценариев в исходном `INSTALL.txt` присутствуют dialplan-контексты `[night_0]`, `[night_1]` и `AGI(night_miss.php,...)`. Это дополнительный модуль, зависящий от маршрутизации FreePBX и `CDR(userfield)`, его установка необязательна для стандартной очереди.

## Серверные файлы и конфигурация

Поместите серверные `.php` и `dashboard.css` в `/var/www/html/qstat/`. Создайте собственный `qstat.inc.php` по шаблону [`../examples/qstat.inc.php.example`](../examples/qstat.inc.php.example) — **не переносите чужие пароли и токены**. Убедитесь, что приватный конфиг не скачивается через HTTP (лучше хранить секреты вне web root и подключать PHP-файлом). Протестируйте:

```bash
php -l /var/www/html/qstat/api.php
php -l /var/www/html/qstat/call.php
php -l /var/www/html/qstat/sync_queue_report-new.php
php -l /var/www/html/qstat/push_worker.php
curl -fsS -H 'Authorization: Bearer API_TOKEN' 'https://YOUR_DOMAIN/qstat/api.php?action=config'
curl -fsS -H 'Authorization: Bearer API_TOKEN' 'https://YOUR_DOMAIN/qstat/api.php?action=agents'
```

**Не отправляйте реальные токены в командную историю и документацию.** Используйте переменные окружения либо интерактивный ввод. В старых конфигурациях Apache может понадобиться проброс `Authorization` через `.htaccess` / виртуальный хост; уже проверено, что без этого PHP выдаёт `401`.

## Автоматический запуск каждые 15 секунд

**Рабочий cron на сервере сохраняется без изменений.** Представленный пользователем `INSTALL.txt` содержит строки исходного расписания, но часть длинных строк обрезана символом `$`, поэтому он не является точной копией действующего `/etc/cron.d/qstat`. Ниже дан лишь альтернативный пример, не замена работающим заданиям. Для документирования фактического расписания используйте `cat /etc/cron.d/qstat` или соответствующий файл в `/etc/cron.d` и сохраняйте секретные пути в приватной конфигурации.

Файл [`../examples/qstat.cron.example`](../examples/qstat.cron.example) — выверенный **пример** для `/etc/cron.d/qstat`, с запуском `sync_queue_report-new.php`, `sync_callbacks-new.php`, `sync_outbound_calls-new.php`, `push_worker.php`. В каждой строке указан `root` как пользователь cron, отдельный lock и смещение `sleep 0/15/30/45`. Измените путь к ключу service-account и права доступа. Более безопасно запускать от выделенного сервисного пользователя с минимально достаточными разрешениями.

В `sync_queue_report-new.php` обнаружена сверка пропущенных звонков: созданные за 10 минут и имеющие `call_time` не старше 2 часов дополнительно добавляются в `cc_push_events`; уникальный индекс `(system_id, callid, event_type)` предотвращает повтор события.

## Контроль

```sql
SELECT * FROM cc_etl_state;
SELECT id, callid, status, call_time FROM cc_calls_report ORDER BY id DESC LIMIT 5;
SELECT id, platform, enabled, last_seen_at FROM cc_push_devices ORDER BY id DESC LIMIT 10;
SELECT id, callid, event_type FROM cc_push_events ORDER BY id DESC LIMIT 5;
SELECT id, event_id, device_id, state, attempts, last_error FROM cc_push_deliveries ORDER BY id DESC LIMIT 10;
```

ВНИМАНИЕ: `push_worker.php` создаёт доставки для всех активных устройств по событиям не старше 7 дней. Перед первым включением worker на базе с существующей историей проверьте незавершённые события, чтобы не получить нежелательную рассылку. Для реального теста создайте один пропущенный звонок через очередь; не вставляйте массово старые события.
