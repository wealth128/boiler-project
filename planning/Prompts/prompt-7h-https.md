# Prompt 7h for Claude Code: HTTPS on the hospital LAN (no IT needed)

_Decided 2026-10-26: HTTPS. Paste after 7g is merged and pushed. Tested for real in the spare-PC test._

```
Step 7h – HTTPS on the hospital network. Create branch step-7h-https. No new packages: use the OpenSSL that comes with Laragon/Apache (openssl.exe) from the Windows scripts. Everything must work without IT: one install step on the server, one double-click per office PC.
1. Certificates (install.bat, and a new renew-cert.bat):
   - Create a private "hospital certificate authority" (CA) valid 10 years, and a server certificate signed by it, valid 825 days (browser limit for trusted certs) with Subject Alternative Names for the server's computer name, its fully qualified name if any, "census-server" alias if used, localhost and the server's current fixed IP address(es).
   - Store the CA private key and server key outside the web folder, readable only by Administrators/SYSTEM (icacls), never in backups' public area, never committed. Back up the CA files into the backup folder once (so a new server can reuse the same CA and office PCs don't need re-trusting).
   - Apache: enable mod_ssl, add a 443 vhost for system/public with the certificate, TLS 1.2+ only, modern ciphers; port 80 only redirects to https://. Check the config before reloading; roll back on error (like 6i-2).
   - Firewall: allow 443 from the LAN; keep 80 only for the redirect.
2. Office PCs: create trust-certificate.bat (and the CA .crt file) in a folder "for-office-pcs" on the server share/USB. Double-click (Run as administrator) installs the CA into Windows "Trusted Root Certification Authorities" so Chrome and Edge trust it; also enable Firefox's enterprise roots if Firefox is installed. It creates a desktop shortcut to https://<server-name>/. Plain-language messages; safe to run twice.
3. Laravel: APP_URL https://..., SESSION_SECURE_COOKIE=true written by census:install when HTTPS is on; HSTS is already sent only on HTTPS (keep max-age moderate, e.g. 1 year, no preload). URLs and redirects use https (trust the request scheme). The public logo, PDF/Excel downloads and print view all work over HTTPS.
4. Expiry: System health check "HTTPS certificate" – OK, Warning 60 days before expiry, Problem when expired or the name/IP no longer matches; census:health too; a yellow Admin bar 30 days before. renew-cert.bat makes a new server certificate from the same CA (office PCs need nothing).
5. Server name or IP changes: renew-cert.bat adds the new name/IP; document it in the recovery guide and troubleshooting ("Not secure" / certificate warning in the browser).
6. Docs: operations guide (HTTPS, renewing, new office PC = run trust-certificate.bat once), troubleshooting (certificate warnings, "Your connection is not private", wrong name, expired), recovery guide (new server keeps the CA from the backup folder), requirements (port 443), the spare-PC test list (install with HTTPS, office PC trust, redirect from http, expiry warning by faking the date), deployment-handover and go-live guide (HTTPS decided). Custodian card: "If a browser says Not secure, run trust-certificate.bat on that PC."
7. Tests (Pest where possible): census:install writes https APP_URL and SESSION_SECURE_COOKIE; health check states for valid / near expiry / expired / name mismatch (use generated test certificates); HSTS only on HTTPS; redirects keep https. List the Windows steps I must test by hand.
8. When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log: HTTPS chosen, local CA 10 years, server cert 825 days, trust-certificate.bat), planning/security.md (HTTPS decision closed) and planning/go-live-guide.md section B.
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize. Don't commit until I say okay.
```
