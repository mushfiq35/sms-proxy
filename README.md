# SMS Proxy — Render Deployment

This tiny service bridges your HTTPS POS apps (piconetit.com/pos/...) to
AGI SMS Gateway's HTTP-only server on non-standard ports (2300/2400) — which
your shared hosting (piconetit.com) is not allowed to reach directly.

## What's in here
- `sms_proxy.php` — the same proxy script used before; unchanged logic.
- `Dockerfile` + `start.sh` — packages it as a small PHP/Apache container
  that Render can run on its free tier (Render doesn't support PHP as a
  native runtime, so Docker is the simplest path).

## Deploy steps (Render.com)

1. Push this folder to a GitHub repo (a new small repo is fine, e.g.
   `sms-proxy`). You already push your POS apps to GitHub, so this is the
   same flow — just a new, separate repo for this one small service.

2. Go to https://dashboard.render.com → **New** → **Web Service**.

3. Connect your GitHub account (if not already) and pick this repo.

4. Render will auto-detect the `Dockerfile`. Settings:
   - **Name**: `sms-proxy` (or anything)
   - **Region**: Singapore (closest to Bangladesh)
   - **Instance Type**: **Free**
   - Leave build/start commands blank — the Dockerfile handles it.

5. Click **Create Web Service**. First deploy takes a few minutes.

6. Once live, Render gives you a URL like:
   `https://sms-proxy-xxxx.onrender.com`

7. Test it directly in your browser:
   `https://sms-proxy-xxxx.onrender.com/sms_proxy.php?baseUrl=http://103.48.119.37:2300&apikey=YOUR_KEY&secretkey=YOUR_SECRET&callerID=YOUR_CALLERID&toUser=01XXXXXXXXX&messageContent=test`

   You should get AGI's JSON response back (ACCEPTD or whatever error, but
   crucially NOT a "Could not connect" network error — Render's outbound
   network isn't port-restricted like shared hosting is).

8. In each POS app's SMS Settings (mushfiq35@gmail.com login only), update
   **Proxy URL** to:
   `https://sms-proxy-xxxx.onrender.com/sms_proxy.php`

   Do this for all 4 POS instances (maxmarketing, kbenterprise, etc.) —
   they can all share this same one proxy service, no need to deploy it
   separately per client.

## Notes
- Free tier sleeps after ~15 min idle; first request after sleep can take
  5–30 seconds to wake up. Fine for this SMS volume.
- No code changes needed in the POS app itself beyond the Proxy URL field.
- If you ever outgrow the free tier, the same Dockerfile deploys as-is on
  Render's paid tier or any VPS — nothing to rewrite.
