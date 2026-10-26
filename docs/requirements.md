# Requirements: what to install first

_Version: DRAFT (Step 6i-2) · Matches system: Step 6i-2 · Phase 7.5 will finish this guide, fill in the screenshots and have it followed step by step by someone who did not write it._

This page has two parts.

- **Part 1: the server PC.** For the person who installs the system at the hospital. No programming tools are needed.
- **Part 2: the developer PC.** For the person who builds the ready-to-install folder.

---

## Part 1: The server PC

### What the server does NOT need

**Composer, Node.js and Git are NOT needed on the server.** The ready-to-install folder already contains everything the system needs to run: the `vendor` folder (the PHP parts) and `public\build` (the screens). You only copy the folder and run `install.bat`.

The server has **no internet**. Every installer below is copied onto the **handover USB** beforehand. Nothing is downloaded on the server.

### Minimum PC

| Item | Minimum | Better |
|---|---|---|
| Windows | Windows 10 or 11, **64-bit** | Windows 11 Pro, all updates installed |
| Memory | 4 GB | 8 GB |
| Free disk space | 10 GB on the drive that holds the system folder | 50 GB |
| Network | Cable to the hospital network, a **fixed IP address** | |
| Power | Never sleeps. On a UPS. | |
| Port 80 | **Free.** No other web server on this PC (IIS, Skype, another Apache, XAMPP). | |

The backups go to a shared folder on a **second PC**, not to the server's own disk.

### What to install, in this order

All files are on the handover USB. The file names below are the ones expected from each vendor's download page. **The "version tested" column is filled in during the spare-PC test** (see `docs/windows-scripts-manual-test.md`). It is empty because the test has not been done yet.

| # | Installer | Expected file name | Version tested | Why |
|---|---|---|---|---|
| 1 | Microsoft Visual C++ Redistributable 2015-2022, **x64** | `vc_redist.x64.exe` | [fill in at the spare-PC test] | PHP, Apache and MySQL will not start without it. |
| 2 | Laragon, **Full** edition (includes Apache, MySQL 8 and PHP) | `laragon-wamp.exe` | [fill in at the spare-PC test] | Gives the server PHP, Apache and MySQL. |
| 3 | Only if Laragon's PHP is older than 8.4.1: PHP, **Thread Safe**, x64 zip | `php-8.4.<n>-Win32-vs17-x64.zip` (or newer) | [fill in at the spare-PC test] | The locked system packages need PHP 8.4.1 or newer. |
| 4 | The ready-to-install folder | `census-system-<date>.zip` | the system version in `config/census.php` | The system itself, with `vendor` and `public\build` inside. |

Do **not** use the "Lite" or "portable" Laragon editions unless you checked that they include MySQL 8 and Apache. `install.bat` checks and tells you.

### Step by step

1. **Prepare Windows.** Install all Windows updates. Set the PC name (for example `CENSUS-SERVER`). Set a fixed IP address. Set "never sleep". Turn on the screen lock after 5 minutes.
   - [screenshot: Settings, System, Power: Sleep set to Never]
   - [screenshot: network adapter properties with the fixed IP address]
2. **Install the Visual C++ Redistributable.** Double-click `vc_redist.x64.exe`. Tick the licence box. Click **Install**. Click **Yes** when Windows asks. Restart the PC if it asks.
   - [screenshot: Visual C++ installer finished]
3. **Install Laragon Full.** Double-click `laragon-wamp.exe`. Keep the folder `C:\laragon`. If the installer has an option to start Laragon or run it with Windows, **untick it**. The system will start by itself later through Windows services.
   - [screenshot: Laragon installer, folder C:\laragon, start-up options unticked]
4. **Open Laragon once and check PHP.** Open Laragon. Right-click its window (or use the **Menu**), choose **PHP**, then **Version**. The ticked version must be **8.4.1 or newer**. If it is older, follow "Adding PHP to Laragon" below first.
   - [screenshot: Laragon menu, PHP, Version]
5. **Check the PHP extensions.** Menu, PHP, **Extensions**. These must be ticked: `gd`, `intl`, `zip`, `mbstring`, `pdo_mysql`, `fileinfo`, `openssl`.
   - [screenshot: Laragon PHP extensions list with the seven ticked]
6. **Check that Laragon starts.** Click **Start All**. Apache and MySQL must both show as running. Leave it running: `install.bat` will tell you when to stop it. **After the install you never click Start All on this server again.**
   - [screenshot: Laragon with Apache and MySQL running]
7. **Copy the system.** Copy the ready-to-install folder from the USB to the server, for example to `C:\census\`. Unzip it so that `C:\census\system\artisan` exists. Use a folder name without spaces.
   - [screenshot: File Explorer showing C:\census\system]
8. **Choose and check the backup folder** (see "Where the backups go" below). If it is a shared folder, open it from the server (for example `\\BACKUP-PC\census-backups`). Make a new empty text file inside it, then delete it. Write down the Windows account that can write there.
   - [screenshot: backup PC shared folder opened from the server]
9. **Run the install.** Follow section 1 of `docs/operations-guide.md` ("First install"). Right-click `system\scripts\windows\install.bat` and choose **Run as administrator**.
   - [screenshot: install.bat requirements check, all OK]

### Where the backups go (BACKUP_PATH)

The system saves a backup every night at 02:00. The install asks where to save them. The answer is stored as `BACKUP_PATH` in the settings file (`.env`). Choose one of these:

- **A shared folder on a second PC**, for example `//BACKUP-PC/census-backups`. This is the best choice.
- **A second disk inside the server**, for example `D:/census-backups`.

**Never use only the server's main disk (C:).** If that disk breaks, the system and its backups are lost together. Type the folder with **forward slashes** (`/`), not backslashes. For a shared folder on another PC, the install asks for a Windows account that can write to it (see the operations guide).

### MySQL root password

Laragon's MySQL starts with the user `root` and **no password**. Anyone who can sit at the server could open the whole database.

- **At install:** `install.bat` (through `census:install`) detects this and makes you set a new password before anything else. It asks twice, nothing shows on the screen, and it must be at least 12 characters. It then says "Write this in the sealed envelope." Write it on the accounts sheet. It is never saved on the PC.
- **After install:** System health has a check called **MySQL root password**. It tries one login as root with an empty password and stores nothing. If MySQL lets it in, the check shows a **Problem**. A bad answer here means a PC where root still has no password.
- **If System health shows that Problem on an existing server:** open Command Prompt as administrator, go to the `bin` folder of Laragon's MySQL (for example `C:\laragon\bin\mysql\mysql-8.0.30\bin`) and type `mysqladmin -u root password`. It asks for the new password. Choose at least 12 characters and write it in the sealed envelope.
- **On a developer PC** the same Problem shows if root has no password. That is accurate and harmless on a PC with no real data.

### What `install.bat` checks before it changes anything

It stops, changes nothing, and says what to do for each line that says **FIX**.

| Check | If it says FIX |
|---|---|
| Windows 10 or 11, 64-bit | Use another PC. |
| Memory at least 4 GB | Add memory, or use another PC. |
| At least 10 GB free disk space | Free space, or move the system folder to a bigger drive. |
| Visual C++ Redistributable (x64) installed | Install `vc_redist.x64.exe` from the USB, restart, run again. |
| Laragon found, with MySQL 8 and Apache | Install Laragon **Full** from the USB. |
| PHP starts and is 8.4.1 or newer | See "Adding PHP to Laragon". |
| PHP is the Thread Safe build | Apache cannot use the Non Thread Safe build. Use the Thread Safe zip. |
| Port 80 is free | Stop the program that uses it. The message names it. |

The next lines come from `php artisan census:install`, which runs right after: the seven PHP extensions, OPcache, the writable folders, the upload limits (2 MB or more) and that MySQL can be reached. Each one also says FIX with one line on what to do.

The Visual C++ check reads the Windows registry. If `php.exe` does not start at all and the registry says it is installed, `install.bat` says so and asks you to choose another PHP in Laragon.

### Adding PHP to Laragon

Do this only if Laragon's own PHP is older than 8.4.1.

1. On a PC with internet, download the **Thread Safe** x64 zip of PHP 8.4 (or newer) from windows.php.net. Its name looks like `php-8.4.<n>-Win32-vs17-x64.zip`. Put it on the handover USB.
2. On the server, unzip it into a new folder inside `C:\laragon\bin\php\`, for example `C:\laragon\bin\php\php-8.4.<n>-Win32-vs17-x64`. The file `php.exe` must sit **directly** in that folder.
3. In Laragon: Menu, PHP, Version. Choose the new folder.
4. Laragon makes its `php.ini` from the template. Open Menu, PHP, Extensions and tick the seven extensions in step 5 above.
5. Run `install.bat`. It uses the newest `php-*` folder in `C:\laragon\bin\php`. If Apache still points to another version, it warns you and tells you to choose the right one in Laragon.

### OPcache (php.ini)

OPcache keeps the compiled PHP files in memory, so the system does not read and compile about 1,500 files on every click. `census:install` shows **OPcache is on** as FIX when it is off, and **System health > Speed settings** shows a Warning. Open Laragon's `php.ini` (Menu, PHP, php.ini) and make sure these lines are there. With PHP 8.4 the line `zend_extension=opcache` must have no `;` in front of it; PHP 8.5 and newer have OPcache built in, so that line is not needed.

```ini
[opcache]
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.save_comments=1
```

Then restart the web server (the **CensusApache** service, or restart the PC). Keep `validate_timestamps=1`: after an update the new files are used within 2 seconds, with no restart. Keep `opcache.save_comments=1`: some libraries read the comments of the code. After `php artisan optimize` (done by `install.bat` and `update.bat`) the server is in its fastest setting; see section 11 of the operations guide.

**Power plan:** set Windows to **High performance** (Settings, System, Power). On "Balanced", Windows moves background programs such as the web server to the slow power-saving cores of newer processors, which made pages 2 to 4 times slower on the developer's laptop.

---

## Part 2: The developer PC

For the person who changes the system and builds the ready-to-install folder.

### What to install

| Tool | Version | Why |
|---|---|---|
| PHP | 8.4.1 or newer, Thread Safe (8.5.5 is what the project is built with) | Runs the code. `composer.json` requires `^8.4.1`, because the locked packages need it. |
| Composer | 2.x (2.9.7 in use) | PHP packages. |
| Node.js | The version in use is **24.15.0** with npm **11.12.1** (`package.json` has no `engines` setting) | Builds the screens. |
| MySQL | 8 | The database for development and for the tests (`patient_census` and `patient_census_test`). |
| Git | Any recent version (2.53.0 in use) | The code history. |
| VS Code | Any recent version | The editor. |
| Claude Code | Latest | The assistant used to build the system. |

PHP extensions needed on the developer PC are the same seven as on the server: `gd`, `intl`, `zip`, `mbstring`, `pdo_mysql`, `fileinfo`, `openssl`.

### Build the ready-to-install folder

Always build in a **clean copy**, never in your working folder. `--no-dev` removes the test tools from `vendor`, which you need while developing. The build below was run on a clean copy of the code to check these steps.

1. Make a clean copy of the committed code in a new empty folder, for example `C:\build\census`:

   ```
   git clone <the repository> C:\build\census
   cd C:\build\census\system
   ```

2. Install the PHP packages for production. Do this **before** step 3: the screen build runs `php artisan` to make its route files.

   ```
   composer install --no-dev --optimize-autoloader
   ```

3. Install the screen tools exactly as locked, then build:

   ```
   npm ci
   npm run build
   ```

4. Clean up what the server must not have. Delete the `node_modules` folder (the server does not need it). Make sure there is **no `.env` file** and no `.git` folder in the folder you will ship. The install makes a new `.env`.
5. Check the result: `vendor` is about 70 MB, `public\build` is about 1.3 MB, and `vendor\composer\platform_check.php` exists.
6. Zip the folder as `census-system-<date>.zip` and put it on the handover USB with the installers from Part 1.

The server must run the same PHP minimum that `vendor\composer\platform_check.php` states (now 8.4.1). If a package update raises it, update this page, `install.bat`'s requirements check and the handover USB list in the same step.

### Run the tests on the developer PC

Tests run **only** on `patient_census_test`, never on `patient_census`. See `CLAUDE.md`.
