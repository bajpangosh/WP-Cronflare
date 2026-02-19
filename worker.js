/*
  Cloudflare Worker — Single-Site WordPress Cron
  -----------------------------------------------
  Triggers wp-cron.php for one WordPress site on a fixed schedule.

  This Worker has two handlers that share the same core logic:

    scheduled()  — called by Cloudflare's Cron Trigger on your chosen schedule.
                   This is the main, automated path.

    fetch()      — called when someone visits the Worker route in a browser
                   (e.g. https://your-site.com/wp-cron.php). Useful for
                   confirming the Worker is set up correctly. Requests to any
                   other path are passed through to your origin unchanged.

  Environment variables required (Settings → Variables and Secrets):
    WP_CRON_URL      — Full base URL of your WordPress site, e.g. https://example.com
    WP_CRON_KEY      — Secret string sent in the X-Worker-Auth header so your
                       server can verify the request came from this Worker.

	See https://github.com/Squarebow/Cloudflare-Worker-for-Wordpress-Cron for instructions
*/

// How long (in milliseconds) to wait for WordPress before giving up.
const FETCH_TIMEOUT_MS = 10000; // 10 seconds

export default {

  // -------------------------------------------------------------------------
  // fetch() — handles normal HTTP requests arriving via the Worker Route.
  //
  // When the path is exactly /wp-cron.php, the Worker triggers WordPress cron
  // and returns a confirmation message to the browser. This lets you verify
  // the setup is working by visiting the URL directly.
  //
  // All other paths (normal site pages, assets, etc.) are passed straight
  // through to your origin server without any modification.
  // -------------------------------------------------------------------------
  async fetch(req, env, ctx) {
    const url = new URL(req.url);

    if (url.pathname === "/wp-cron.php") {
      // Trigger cron and return the result directly to the browser.
      return triggerCron(env.WP_CRON_URL, env.WP_CRON_KEY);
    }

    // Any other path: let the request continue to the origin unchanged.
    return fetch(req);
  },

  // -------------------------------------------------------------------------
  // scheduled() — called automatically by Cloudflare on every Cron Trigger.
  //
  // ctx.waitUntil() keeps the Worker alive until the fetch completes, even
  // after scheduled() itself has returned.
  // -------------------------------------------------------------------------
  async scheduled(event, env, ctx) {
    console.log(`WP cron Worker triggered at: ${new Date(event.scheduledTime).toISOString()}`);
    ctx.waitUntil(triggerCron(env.WP_CRON_URL, env.WP_CRON_KEY));
  },

};

// -----------------------------------------------------------------------------
// triggerCron()
//
// Fires an HTTP GET request to wp-cron.php on the target WordPress site.
// Returns an HTTP Response in both success and error cases, which is used
// directly by the fetch() handler. The scheduled() handler discards the
// response, but the same function is shared to keep the logic consistent.
//
// @param {string} siteUrl   - Full base URL of the WordPress site (e.g. https://example.com)
// @param {string} secretKey - Secret sent in the X-Worker-Auth header
// @returns {Promise<Response>}
// -----------------------------------------------------------------------------
async function triggerCron(siteUrl, secretKey) {

  // --- Validate environment variables are present ---
  if (!siteUrl) {
    const msg = 'Missing environment variable: WP_CRON_URL. Add it in Worker Settings → Variables and Secrets.';
    console.error(msg);
    return new Response(msg, { status: 500 });
  }
  if (!secretKey) {
    const msg = 'Missing environment variable: WP_CRON_KEY. Add it in Worker Settings → Variables and Secrets.';
    console.error(msg);
    return new Response(msg, { status: 500 });
  }

  // Strip any trailing slash to avoid double slashes in the final URL.
  const baseUrl = siteUrl.replace(/\/+$/, "");
  const cronUrl = `${baseUrl}/wp-cron.php?doing_wp_cron`;

  // --- Set up a request timeout ---
  // AbortController lets us cancel the fetch if the server takes too long.
  const controller = new AbortController();
  const timeoutId  = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

  try {
    const response = await fetch(cronUrl, {
      method: "GET",
      headers: {
        // Identifies this request as coming from the Worker (visible in server logs).
        "User-Agent":    "Cloudflare-Worker-WP-Cron",
        // Custom header used to authenticate the request on the WordPress/server side.
        "X-Worker-Auth": secretKey,
        // Prevent any caching of this request by Cloudflare's edge.
        "Cache-Control": "no-cache",
      },
      // Also tell Cloudflare's fetch implementation not to cache the response.
      cf: { cacheTtl: 0 },
      signal: controller.signal,
    });

    if (!response.ok) {
      // Read a snippet of the response body to include in the error log.
      const body = await safeText(response, 500);
      const msg  = `HTTP ${response.status} ${response.statusText} from ${baseUrl} — ${body}`;
      console.error(`WP cron failed: ${msg}`);
      return new Response(`Cron failed: ${msg}`, { status: 500 });
    }

    console.log(`WP cron OK (HTTP ${response.status}) for: ${baseUrl}`);

    // Return the confirmation message to the browser when tested manually.
    return new Response("Cloudflare Worker for WordPress works. Yay!", { status: 200 });

  } catch (err) {
    if (err.name === "AbortError") {
      const msg = `Timeout after ${FETCH_TIMEOUT_MS}ms waiting for: ${baseUrl}`;
      console.error(msg);
      return new Response(msg, { status: 504 });
    }
    console.error(`Network or runtime error for ${baseUrl}:`, err);
    return new Response(`Error triggering WP cron: ${err.message}`, { status: 500 });
  } finally {
    // Always clear the timeout, whether the fetch succeeded or failed.
    clearTimeout(timeoutId);
  }
}

// -----------------------------------------------------------------------------
// safeText()
//
// Safely reads a limited number of characters from a fetch Response body.
// Returns a fallback string if reading fails for any reason.
//
// @param {Response} response  - The fetch Response object
// @param {number}   maxLength - Maximum characters to return (default: 200)
// @returns {Promise<string>}
// -----------------------------------------------------------------------------
async function safeText(response, maxLength = 200) {
  try {
    const text = await response.text();
    return text.slice(0, maxLength).trim() || "(empty body)";
  } catch {
    return "(could not read response body)";
  }
}
