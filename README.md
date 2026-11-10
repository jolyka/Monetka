# Laravel + Vue Starter Kit

## Introduction

Our Vue starter kit provides a robust, modern starting point for building Laravel applications with a Vue frontend using [Inertia](https://inertiajs.com).

Inertia allows you to build modern, single-page Vue applications using classic server-side routing and controllers. This lets you enjoy the frontend power of Vue combined with the incredible backend productivity of Laravel and lightning-fast Vite compilation.

This Vue starter kit utilizes Vue 3 and the Composition API, TypeScript, Tailwind, and the [shadcn-vue](https://www.shadcn-vue.com) component library.

## Official Documentation

Documentation for all Laravel starter kits can be found on the [Laravel website](https://laravel.com/docs/starter-kits).

## Contributing

Thank you for considering contributing to our starter kit! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

All contributions to the Starter Kits from now on should be made through [Maestro](https://github.com/laravel/maestro).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## License

The Laravel + Vue starter kit is open-sourced software licensed under the MIT license.

## Запуск на малом сервере

Для сервера с 0,5 vCPU собирайте фронтенд и устанавливайте зависимости на машине сборки с той же платформой PHP, затем переносите готовые `vendor` и `public/build` вместе с приложением. Vite, `npm run dev` и `composer dev` на рабочем сервере не нужны. Укажите для веб-сервера корневой каталог `public`.

В рабочем `.env` задайте `APP_ENV=production`, `APP_DEBUG=false`, действующий `APP_KEY` и адрес `APP_URL`. После установки зависимостей и настройки окружения выполните:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
```

Команды Composer и npm выполняются на машине сборки, если её используете; миграции и `optimize` — на сервере после настройки `.env`. После изменения конфигурации приложения обновляйте кеш командой `php artisan optimize`, а после изменения настроек окружения перезапускайте PHP-FPM. Включите OPcache для PHP-FPM. Для небольшого личного экземпляра начните с одного процесса PHP-FPM (`pm.max_children = 1`): параллельные запросы будут ожидать, зато процессы не размножатся. При достаточной памяти и необходимости параллельных запросов увеличьте число процессов. Настраивайте ограничение в 0,5 CPU на уровне хостинга или контейнера: код приложения сам по себе не ограничивает выделенные процессорные ресурсы.

Планировщик рекомендаций необязателен для работы интерфейса. Если он нужен, добавьте обычный запуск `php artisan schedule:run` раз в минуту через cron. Локальные модели Ollama и whisper.cpp могут требовать значительно больше CPU и памяти; на слабом сервере используйте внешний AI API и не включайте локальную обработку голоса. Производительность зависит от размера базы, памяти, диска и числа пользователей; приведённая конфигурация не гарантирует конкретное время ответа.
