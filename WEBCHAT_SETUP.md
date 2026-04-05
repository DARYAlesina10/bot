# Live-чат сайта → Telegram mini CRM

Этот комплект добавляет виджет чата на сайт и прокидывает сообщения в Telegram-треды CRM.

## Что уже добавлено

- `webchat_api.php` — API для виджета:
  - `action=init` — создаёт `session_id`
  - `action=send` — отправляет сообщение клиента в Telegram-тред
  - `action=poll` — отдаёт ответы оператора из Telegram обратно в виджет
- `webchat_widget.js` — фронтовый виджет для сайта
- Обработка ответов менеджера из Telegram в `bot.php`:
  - если тред содержит маркер `[ext: web_<session_id>]`, ответ уходит в веб-чат.

## 1) Разместить файлы

Загрузите в `/telegramm/` на вашем домене:

- `bot.php`
- `webchat_api.php`
- `webchat_widget.js`
- директорию `webchat_data/` (или дайте PHP права создать её автоматически)

Права записи нужны для `webchat_data/` и `webchat_data/messages/`.

## 2) Вставить виджет на сайт

Перед `</body>` добавьте:

```html
<script>
  window.PANDOROOM_CHAT_API = "https://tgbotum145.ru/telegramm/webchat_api.php";
</script>
<script src="https://tgbotum145.ru/telegramm/webchat_widget.js"></script>
```

## 3) Проверка сценария

1. Откройте сайт, отправьте сообщение из виджета.
2. В Telegram CRM должен создаться новый тред вида:
   - `🌐 Live chat <имя> [ext: web_<session_id>]`
3. Ответьте менеджером в этот тред.
4. Ответ должен появиться у посетителя в виджете через 1–3 секунды.

## 4) Важно

- В `webchat_api.php` используется тот же токен Telegram-бота, что и в `bot.php`.
- `WEBCHAT_SUPPORT_CHAT_ID` должен совпадать с `SUPPORT_CHAT_ID` из `bot.php`.
- Веб-чат сейчас текстовый (фото/документы от сайта не реализованы).
