# Home Setup Guide: Continue the Project on Your Own PC with Claude Pro

_Last updated: 2026-10-15_

Goal: keep building at home with your personal Claude Pro account, with the same quality and the same way of working as at the office. The project lives in GitHub, so nothing is lost when you switch PCs or accounts. A new Claude conversation can't see the old one, so `planning/project-history.md` carries over what we discussed.

---

## Part A. Before You Leave the Office
1. Finish Step 6c: send the pending message in `planning/project-history.md` section 5 to Claude Code. It also adds the history file to `CLAUDE.md` and commits this guide.
2. Push: `git push origin main`
3. On GitHub, open the repo and confirm `planning/project-history.md` and `planning/home-setup-guide.md` are there.
4. **Write down privately** (not in GitHub, not in chat) the values from `system/.env` you'll need at home: `DB_PASSWORD`, `SEED_ADMIN_PASSWORD`, `SEED_SYSADMIN_PASSWORD` and the dev-user passwords. You can also just choose new ones at home.
5. If any of this was done on company time or equipment, confirm with your supervisor that continuing it as a personal project is fine.

## Part B. Get Claude Pro (personal account)
1. Sign in at claude.ai with your **personal** email.
2. Upgrade to **Pro**. Check the current price and what's included at claude.com/pricing. As far as we know, Pro includes Claude Code; confirm it's listed before paying.
3. You can cancel after the system is finished.

## Part C. Install the Tools on Your Home PC
Install the same versions as the office PC:

| Tool | Version | Check with |
|---|---|---|
| PHP | 8.3 (extensions: pdo_mysql, mbstring, intl, zip, gd, fileinfo, openssl) | `php -v` |
| Composer | latest | `composer -V` |
| Node.js | 20 or higher | `node -v` |
| MySQL | 8.x | `mysql --version` |
| Git | latest | `git --version` |
| VS Code | latest | — |

**Easier option on Windows:** install **Laragon** (it includes PHP, MySQL and Composer). It's the same tool planned for the hospital server, so it's good practice too.

Set your Git name and email once:
```
git config --global user.name "Your Name"
git config --global user.email "your.personal@email.com"
```

## Part D. Get the Project Running
**1. Clone the repo**
```
cd %USERPROFILE%\Desktop
git clone https://github.com/wealth128/boiler-project.git
cd boiler-project\system
```
(GitHub will ask you to sign in, since the repo is private.)

**2. Create the databases** (in `mysql -u root -p`):
```
CREATE DATABASE patient_census CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE patient_census_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'census_app'@'localhost' IDENTIFIED BY 'YourPasswordHere';
GRANT ALL PRIVILEGES ON patient_census.* TO 'census_app'@'localhost';
GRANT ALL PRIVILEGES ON patient_census_test.* TO 'census_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**3. Create `.env`** (it's not in GitHub because it holds passwords):
```
copy .env.example .env
```
Open `system\.env` and fill in `DB_PASSWORD`, `SEED_ADMIN_PASSWORD`, `SEED_SYSADMIN_PASSWORD` and the dev-user passwords.

**4. Install and set up**
```
composer install
npm install
php artisan key:generate
php artisan migrate --seed
```

**5. Check everything works**
```
php artisan test
npm run types:check
npm run build
```
All three must pass. `php artisan test` must use `patient_census_test` (it's set in `phpunit.xml`). If it fails to connect, stop and fix the test database; never point tests at `patient_census`.

**6. Run it**
```
composer run dev
```
Open http://127.0.0.1:8000 and log in as `admin`.

## Part E. Set Up Claude Code (the builder)
1. In VS Code, install the **Claude Code** extension from the Extensions panel, or install the command-line version (`npm install -g @anthropic-ai/claude-code`, then run `claude` in the project folder). If these steps changed, follow docs.claude.com.
2. Sign in with your **personal Pro** account.
3. Open the `boiler-project` folder in VS Code. Claude Code reads `CLAUDE.md` automatically.
4. First message to Claude Code:
```
Read CLAUDE.md and planning/project-history.md. Tell me in a short summary where the project is and what the next step is. Don't change anything.
```
If its summary matches section 5 of the history file, you're ready.

## Part F. Set Up the Reviewer Conversation (the planner)
This replaces the old review conversation.
1. At claude.ai, create a **Project** named "AFP Census System".
2. Add these files to the Project's knowledge: `CLAUDE.md`, `planning/project-history.md`, `planning/mvp-scope.md`, `planning/rbac.md`, `planning/database-schema.md`, `planning/security.md`, `planning/setup-and-structure.md`, `planning/project-phases.md`, `prototype/prototype.html`, and the latest prompt in `planning/Prompts/`.
3. In the Project's instructions, paste:
```
You are the reviewer and planner for my AFP hospital patient encoding & reporting system. Claude Code builds; you write each step's prompt from the planning files and prototype, review Claude Code's summaries and the code I paste, and explain in plain language. Follow planning/project-history.md section 3 (how I want to be helped) and section 7 (reviewer checklist). Never decide for me: recommend, and say what is new versus already agreed. Keep it short and give me the exact message to paste into Claude Code.
```
4. Start each new chat in that Project with:
```
Read project-history.md. We are at <step>. Next I need <what>.
```
5. When something important is decided, ask the reviewer to **update `planning/project-history.md`** (decisions log and status), then commit it, and re-upload it to the Project. This keeps every future conversation up to date.

Note: this reviewer can't open files on your PC directly. Paste Claude Code's summary, and paste code files when you want a code review (controller, request, routes, tests).

## Part G. Daily Routine
**Start of session**
```
git checkout main
git pull
```
**Each step**
1. Reviewer writes the prompt → save it in `planning/Prompts/`.
2. Paste it into Claude Code. It builds on a new branch and stops.
3. Paste the summary (and key code files) to the reviewer.
4. Do the browser checks.
5. Tell Claude Code to commit and merge, then `git push origin main`.

**End of session:** always push. If you ever work on both PCs, `git pull` first every time.

## Part H. If Something Goes Wrong
| Problem | Fix |
|---|---|
| `php artisan test` can't connect | Check `patient_census_test` exists and `census_app` has rights on it |
| `composer install` fails on an extension | Enable it in `php.ini` (e.g. `extension=intl`), restart the terminal |
| `npm run build` fails after an update | Check the vite-plus note in `CLAUDE.md` |
| Login says the account is locked | `php artisan census:reset-admin-password` (Admin), or reseed: `php artisan migrate:fresh --seed` (wipes the dev data) |
| A file you saved looks old again | VS Code had an old copy open: close the tab and reopen |
| Claude Code seems to forget rules | Tell it: "Read CLAUDE.md and planning/project-history.md again." |
| Usage limit reached | Wait for it to reset; keep steps small (split big prompts like Reports into 6d-1, 6d-2, 6d-3) |
