# Серверный API — краткая спецификация по исходному `api.php`

Базовый URL: `https://YOUR_DOMAIN/qstat/api.php`. Мобильный интерфейс просит пользователя ввести только `https://YOUR_DOMAIN/qstat`, добавляя `api.php` автоматически. При включённой `$api_auth_enabled` требуется `Authorization: Bearer ...`.

| Метод | Путь | Назначение |
|---|---|---|
| GET | `api.php?action=config` | `{"ok":true,"mobile_name":"..."}` (fallback `DashBoard`) |
| GET | `api.php?action=agents` | `{"ok":true,"agents":[{"number":"1701","name":"Хостес 1"}]}` |
| GET | `api.php?type=incoming` | Входящие вызовы, статусы/поиск/период/пагинация |
| GET | `api.php?type=outgoing` | Исходящие вызовы, статусы/период/пагинация |
| POST | `api.php?action=register_push` | JSON `installation_id`, `platform` = `android`/`ios`, `fcm_token` |
| POST | `api.php?action=unregister_push` | JSON с `installation_id`; отключение устройства |
| POST | `call.php` | JSON `{"from":"1701","to":"79991234567"}`; при успешной команде AMI `{"ok":true}` |

`call.php` поддерживает также старый веб-GET с PHP-сессией и CSRF-токеном. Для мобильного POST требуется Bearer. Вызов AMI `Originate` с `Async: true` означает принятие команды Asterisk, **не** ответ клиента на звонок. Доступный агент сверяется со списком `$call_agents`.

Типичные ошибки: HTTP 401 — неверный Bearer/заголовок не дошёл до PHP; 400 — неверное поле запроса; 403 — запрет действия; 429 — ограничение частоты звонков; 502 — AMI не подключился/не принял Originate. Для пустого `client_name`/`queue_number` мобильное приложение показывает прочерк.

**Конфиденциальность:** API возвращает номера/имена клиентов; не публикуйте URL с реальными Bearer-токенами или ответы API. Установите ограничения доступа и журналирование без секретов.

## План следующей версии

Перенести операции `call.php` и `recordings.php` внутрь `api.php` с централизованной авторизацией. В текущем рабочем релизе endpoints и поведение **не меняются**.
