/*
 * gputemp.js — dashboard tile poller for the gputemp Unraid plugin.
 *
 * Design points (per SRS FR-3.5 / PR-4.2 / UX-5.6 / UX-5.7):
 *  - Anchor self-correcting schedule: the next tick fires at
 *    anchor + (n+1)*T computed from performance.now(), so timer drift
 *    does not accumulate (no setInterval).
 *  - Overlap protection: an in-flight flag plus AbortController; when the
 *    previous request is still running at tick time it is aborted (counted
 *    as a timeout) before a new one starts — requests never pile up.
 *  - fetch(no-store): only textContent / className of the affected spans are
 *    mutated; the target row is located via the data-pci attribute
 *    (".gputemp-row[data-pci=\"<pci>\"]") and the spans inside it via the
 *    classes ".gputemp-temp" (line 1, right side) / ".gputemp-mem"
 *    (line 2 detail: "x.x / y.y GB", or "N/A" in the identical style when
 *    mem_used/mem_total are null).
 *    No full page reload, no innerHTML, no stale temperature is ever shown.
 *  - The backend already classifies status (ok|warn|crit|timeout|unavailable);
 *    this script only maps status -> CSS color class.
 *
 * Runtime config is injected by gputemp.tile.page as window.GPUTEMP_CONFIG:
 *   { refreshInterval, tempWarn, tempCrit, collectTimeout, endpoint,
 *     i18n: { readTimeout, unavailable, driverMissing } }
 */
(function () {
  'use strict';

  var config = window.GPUTEMP_CONFIG || {};
  var intervalMs = (parseInt(config.refreshInterval, 10) || 5) * 1000;
  // Abort a hung request slightly before the next tick would fire.
  var abortMs = Math.max(1500, Math.min(intervalMs, ((parseInt(config.collectTimeout, 10) || 5) + 2) * 1000));
  var endpoint = config.endpoint || '/plugins/gputemp/api/gputemp.php';
  var i18n = config.i18n || {};
  var msgTimeout = i18n.readTimeout || 'Read timeout';
  var msgUnavailable = i18n.unavailable || 'Unavailable';
  var msgDriver = i18n.driverMissing || 'Driver missing';

  var STATUS_CLASS = { ok: 'green-text', warn: 'warn-text', crit: 'crit-text' };

  var controller = null; // AbortController of the in-flight request
  var inFlight = false;
  var anchor = performance.now();
  var tickCount = 0;

  function esc(s) {
    return String(s).replace(/[!"$&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
  }

  function findRow(pci) {
    return document.querySelector('.gputemp-row[data-pci="' + esc(pci) + '"]');
  }

  function setTemp(row, text, cls) {
    var tempEl = row.querySelector('.gputemp-temp');
    if (!tempEl) return;
    tempEl.textContent = text;
    tempEl.className = 'gputemp-temp ' + cls;
  }

  function setMem(row, text) {
    var memEl = row.querySelector('.gputemp-mem');
    if (!memEl) return;
    memEl.textContent = text;
  }

  // Memory usage formatting: MiB -> GB with one decimal, "x.x / y.y GB";
  // missing data source or failed read (null) -> "N/A".
  function formatMem(memUsed, memTotal) {
    if (memUsed === null || memUsed === undefined ||
        memTotal === null || memTotal === undefined) return 'N/A';
    var usedGb = (Number(memUsed) / 1024).toFixed(1);
    var totalGb = (Number(memTotal) / 1024).toFixed(1);
    return usedGb + ' / ' + totalGb + ' GB';
  }

  function renderTimeout(row) {
    setTemp(row, msgTimeout, 'red-text');
    setMem(row, 'N/A'); // never keep stale memory figures on a failed cycle
  }

  function markAllFailed() {
    // HTTP / JSON / abort failure: one failed cycle for every visible row.
    // Never keep stale numbers on screen.
    var rows = document.querySelectorAll('.gputemp-row');
    for (var i = 0; i < rows.length; i++) renderTimeout(rows[i]);
  }

  function renderGpu(gpu) {
    if (!gpu || gpu.pci === undefined || gpu.pci === null) return;
    var row = findRow(gpu.pci);
    if (!row) return;

    var status = String(gpu.status || '');
    if (status === 'ok' || status === 'warn' || status === 'crit') {
      setTemp(row, (gpu.temp === null || gpu.temp === undefined) ? '--' : gpu.temp + '\u2103', STATUS_CLASS[status]);
      setMem(row, formatMem(gpu.mem_used, gpu.mem_total));
    } else if (status === 'timeout') {
      renderTimeout(row);
    } else if (status === 'unavailable') {
      setTemp(row, '[' + msgUnavailable + ']', 'red-text');
      setMem(row, 'N/A');
    } else if (status === 'driver_missing') {
      setTemp(row, msgDriver, 'red-text');
      setMem(row, 'N/A');
    } else {
      // Unknown status: safe placeholder, never a stale temperature.
      setTemp(row, '[' + msgUnavailable + ']', 'red-text');
      setMem(row, 'N/A');
    }
  }

  function pollOnce() {
    if (inFlight && controller) {
      // Previous cycle still running: abort it (counts as a timeout),
      // never queue concurrent requests.
      controller.abort();
    }
    inFlight = true;
    controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;

    var abortTimer = null;
    if (controller) {
      abortTimer = setTimeout(function () { controller.abort(); }, abortMs);
    }

    fetch(endpoint, {
      method: 'GET',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      signal: controller ? controller.signal : undefined
    }).then(function (resp) {
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      return resp.json();
    }).then(function (data) {
      if (!data || !Array.isArray(data.gpus)) throw new Error('bad payload');
      for (var i = 0; i < data.gpus.length; i++) renderGpu(data.gpus[i]);
    }).catch(function () {
      // Network error, abort, HTTP error or malformed JSON all count as
      // one failed cycle — rows show the red timeout placeholder.
      markAllFailed();
    }).then(function () {
      if (abortTimer) clearTimeout(abortTimer);
      inFlight = false;
      controller = null;
    });
  }

  function tick() {
    if (!document.querySelector('#gputemp-tile')) return; // tile gone (page change)
    pollOnce();
    // Self-correcting anchor: next delay absorbs any accumulated drift.
    tickCount += 1;
    var target = anchor + tickCount * intervalMs;
    var delay = Math.max(50, target - performance.now());
    setTimeout(tick, delay);
  }

  function start() {
    if (!document.querySelector('#gputemp-tile')) return;
    pollOnce();
    setTimeout(tick, intervalMs);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
