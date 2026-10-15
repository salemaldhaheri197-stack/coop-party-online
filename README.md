# Coop Party Online

A simple online multiplayer party board game inspired by classic Mario Party-style games.

## Features
- Host or join rooms with 4-letter codes
- Up to 4 players (including CPU bots)
- Board game with minigames, coins, and stars
- Real-time updates via a lightweight PHP backend

## Files
- `index.html` — Frontend (single-file HTML/JS/CSS)
- `api.php` — Backend room server (stores rooms as JSON files)

## Requirements
- A web server with PHP support
- The `rooms/` directory must be writable by the web server (the script creates it automatically and adds a `.htaccess` to deny direct access)

## How to run
1. Upload `index.html` and `api.php` to your PHP-enabled web host.
2. Open `index.html` in a browser.
3. Create a room or join with a code.

## Notes
- Rooms are stored as JSON files and auto-cleaned after 24 hours of inactivity.
- Designed for small groups / local play over the internet.
