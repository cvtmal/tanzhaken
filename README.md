# TanzHaken

TanzHaken (working name) is a lightweight, mobile-first web application for independent dance teachers that replaces printed attendance sheets. A teacher creates recurring classes, keeps a roster of students, issues lesson credits (usually in packs of 10) and opens a short-lived QR-code check-in after each class. Students scan the code, pick their name from the class roster and confirm they attended; the app records the attendance and shows the remaining prepaid balance. Teachers can review, correct and export the register.

## Requirements

- PHP 8.4 with the `intl` and `pdo_sqlite` extensions
- [Composer](https://getcomposer.org/)
- [Symfony CLI](https://symfony.com/download) (local web server)

No Node, no Docker, no database server: development and tests use SQLite files under `var/`.

## Start locally

```sh
make init    # install dependencies and create the dev database
make serve   # start the server in the background on http://127.0.0.1:8000
make seed    # load demo data
make serve-stop
```

`make help` lists every target.

## Checks

```sh
make lint    # code style, PHPStan (level 8, architecture rules), Twig/YAML/container lint
make test    # all PHPUnit suites (Unit, Integration, Functional, Architecture)
```

Both run in CI on every push to `main` and on every pull request.

## Configuration

Defaults live in `.env` (committed). Put local overrides and secrets in `.env.local`, which is never committed. [`.env.example`](.env.example) lists every variable with a placeholder value.

## Documentation

- [`PRD_TanzHaken_MVP.md`](PRD_TanzHaken_MVP.md): product scope
- [`docs/rpi-cycles.md`](docs/rpi-cycles.md): build plan and settled technical decisions
- [`docs/outcomes/`](docs/outcomes/): what each finished cycle delivered
- [`docs/design/`](docs/design/): design source and reference
