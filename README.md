# OCCWeb Terminal (Updated for Nextcloud 35)

### A web terminal for administrators to run Nextcloud `occ` commands directly from the browser

![occweb](https://github.com/Adphi/OCCWeb/raw/main/appinfo/screenshot.png)

This repository is updated and modernized to provide full compatibility with **Nextcloud 35** (as well as Nextcloud 31–34) and **PHP 8.2+ through PHP 8.5**.

---

### Highlights & Compatibility Updates

* **Nextcloud 35 & Modern DI Container:** Replaced hardcoded `OC\Console\Application` constructor parameter bindings with Nextcloud's Dependency Injection container resolution and dynamic reflection fallback, preventing constructor breakage across Nextcloud releases.
* **Bypass Web Request argv Issue:** Direct execution via the underlying Symfony Console application avoids `ConsoleEvent` crashes caused by missing `$_SERVER['argv']` during web requests.
* **Self-Contained Frontend (jQuery Bundling):** Nextcloud removed global jQuery from core templates in recent releases. OCCWeb bundles its own jQuery library to guarantee terminal functionality without runtime errors.
* **PHP 8 Attributes & Strict Types:** Uses native `#[NoCSRFRequired]` attributes alongside backwards-compatible docblock annotations, matching modern Nextcloud App Framework conventions.
* **Enhanced Admin Access Control:** Strictly restricts terminal execution to authenticated users with Nextcloud administrator privileges (`IGroupManager::isAdmin`).
* **Command Usability:** Accepts both `occ <command>` and `<command>` syntax, plus tab auto-completion for all available commands.
* **Safeguards:** Non-interactive mode (`--no-interaction`) is enforced to prevent commands from hanging PHP worker threads on prompts. Added explicit protection against accidental `maintenance:mode --on` web interface lockouts.

---

### Installation

1. Clone or place this folder into your Nextcloud `apps/` or `custom_apps/` directory named `occweb`:
   ```bash
   cd /var/www/nextcloud/apps/
   git clone https://github.com/Adphi/occweb.git occweb
   ```
2. Set appropriate web server permissions:
   ```bash
   chown -R www-data:www-data /var/www/nextcloud/apps/occweb
   ```
3. Enable the application:
   ```bash
   sudo -u www-data php /var/www/nextcloud/occ app:enable occweb
   ```
4. Access the web terminal by clicking the **OCCWeb** icon in the Nextcloud navigation bar as an administrator.

---

### ⚠️ Warnings & Limitations

- **Not an Interactive TTY:** Web requests execute synchronously over HTTP. Commands that prompt for interactive user input will run with default options or exit without waiting.
- **PHP Execution Timeouts:** Long-running maintenance commands on huge instances (such as large `files:scan --all` runs) can exceed PHP/web server execution timeouts (`max_execution_time`). For intensive maintenance, use direct SSH access.
- **Maintenance Mode:** Avoid enabling maintenance mode from the web interface (`occ maintenance:mode --on`), as this renders the web UI immediately inaccessible.

---

### License

Licensed under the [GNU Affero General Public License v3.0 (AGPL-3.0)](COPYING).
