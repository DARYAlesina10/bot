# MAX bot copy + Telegram CRM bridge

Эта папка содержит копию бота для MAX и мост в Telegram CRM.

## Что важно
- `bot_max.php` — версия логики под MAX API.
- `max_bridge_daemon.php` — двусторонний мост:
  - сообщения клиента из MAX -> в Telegram CRM (в topic с меткой MAX)
  - ответы менеджера в этом topic -> обратно клиенту в MAX

## Переменные окружения
Обязательно:
- `MAX_BOT_TOKEN`
- `TELEGRAM_CRM_BOT_TOKEN`

Опционально:
- `MAX_API_URL` (по умолчанию `https://platform-api.max.ru/`)

## Запуск моста
```bash
cd max_bot
MAX_BOT_TOKEN='...' TELEGRAM_CRM_BOT_TOKEN='...' php max_bridge_daemon.php
```

## Что делает мост
1. Слушает `GET /updates` в MAX (long polling).
2. На первое сообщение пользователя создаёт topic в Telegram CRM (`SUPPORT_CHAT_ID`) и сохраняет связку user_id <-> thread_id в `max_telegram_threads.json`.
3. Пересылает каждое сообщение клиента в этот topic с пометкой `🟣 MAX`.
4. Читает ответы менеджеров в Telegram (`getUpdates`) и отправляет их клиенту в MAX с подписью `Команда Pandoroom`.
5. При ответах клиенту добавляет inline-клавиатуру с теми же клиентскими кнопками, что и в Telegram-боте.

## Файлы состояния
- `max_telegram_threads.json` — связка MAX user и Telegram thread.
- `max_bridge_state.json` — `marker` MAX и `offset` Telegram.

## Примечание
Основной Telegram-бот (`/workspace/bot/bot.php`) не изменяется.
