# MAX bot copy

Это отдельная копия бота для мессенджера **MAX**.

## Что сделано
- Скопирован текущий функционал Telegram-бота в `max_bot/bot_max.php`.
- Основной Telegram-бот в корне проекта не изменяется.
- Добавлен адаптер API для MAX:
  - JSON-запросы
  - `Authorization: Bearer <token>`
  - алиасы методов (чтобы плавно сопоставлять telegram-style имена с endpoint-ами MAX)
- Добавлена нормализация webhook update под ожидаемую структуру (`message`, `callback_query`, `message_reaction`).

## Конфигурация
Используются переменные окружения:
- `MAX_BOT_TOKEN`
- `MAX_API_URL` (базовый URL API MAX, например `https://api.max.ru/bot`)

## Быстрый старт
1. Настрой переменные окружения `MAX_BOT_TOKEN` и `MAX_API_URL`.
2. Направь webhook MAX на `max_bot/bot_max.php`.
3. Проверь доступы бота к чату поддержки и тредам (если в MAX используются аналогичные сущности).

## Важно
- Бизнес-логика скопирована из Telegram-версии.
- Если конкретные endpoint-ы в MAX отличаются, обнови маппинг в `maxApiMethodAliases()` в `bot_max.php`.
- Документация: https://dev.max.ru/docs-api
